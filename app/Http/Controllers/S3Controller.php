<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Bucket;
use App\Models\MultipartPart;
use App\Models\MultipartUpload;
use App\Models\ObjectRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class S3Controller extends Controller
{
    private function xml(array $data, int $status = 200)
    {
        return response()->view('s3.xml', $data, $status, ['Content-Type' => 'application/xml']);
    }

    private function bucket(string $name)
    {
        return Bucket::where('name', $name)->firstOrFail();
    }

    private function path(Bucket $b, string $key)
    {
        return config('object-store.root').DIRECTORY_SEPARATOR.$b->id.DIRECTORY_SEPARATOR.hash('sha256', $key);
    }

    private function audit(Request $r, string $action, ?string $bucket = null, ?string $key = null): void
    {
        AuditEvent::create(['request_id' => $r->header('x-amz-request-id'), 'actor' => optional($r->attributes->get('s3_key'))->access_key, 'action' => $action, 'bucket' => $bucket, 'object_key' => $key, 'ip' => $r->ip(), 'context' => ['method' => $r->method()]]);
    }

    public function buckets()
    {
        return $this->xml(['buckets' => Bucket::orderBy('name')->get()]);
    }

    public function createBucket(Request $r, string $bucket)
    {
        Bucket::firstOrCreate(['name' => $bucket]);
        $this->audit($r, 'bucket.create', $bucket);

        return response('', 200);
    }

    public function headBucket(string $bucket)
    {
        $this->bucket($bucket);

        return response('', 200);
    }

    public function deleteBucket(Request $request, string $bucket)
    {
        $bucketModel = $this->bucket($bucket);

        if ($bucketModel->objects()->exists()) {
            abort(409, 'Bucket must be empty before it can be deleted.');
        }

        File::deleteDirectory(config('object-store.root').DIRECTORY_SEPARATOR.$bucketModel->id);
        $bucketModel->delete();
        $this->audit($request, 'bucket.delete', $bucket);

        return response('', 204);
    }

    public function list(Request $r, string $bucket)
    {
        $b = $this->bucket($bucket);
        $maxKeys = min(max($r->integer('max-keys', 1000), 1), 1000);
        $prefix = $r->string('prefix')->toString();
        $continuationToken = $r->string('continuation-token')->toString();
        $lastKey = $continuationToken === '' ? null : base64_decode(strtr($continuationToken, '-_', '+/'), true);
        $query = $b->objects()->whereNull('deleted_at')->where('key', 'like', $prefix.'%')->orderBy('key');

        if ($lastKey !== false && $lastKey !== null) {
            $query->where('key', '>', $lastKey);
        }

        $objects = $query->limit($maxKeys + 1)->get();
        $isTruncated = $objects->count() > $maxKeys;
        $objects = $objects->take($maxKeys)->values();
        $nextToken = $isTruncated ? rtrim(strtr(base64_encode($objects->last()->key), '+/', '-_'), '=') : null;

        return $this->xml(['bucket' => $b, 'objects' => $objects, 'prefix' => $prefix, 'isTruncated' => $isTruncated, 'nextToken' => $nextToken]);
    }

    public function put(Request $r, string $bucket, string $key)
    {
        if ($r->filled('uploadId')) {
            return $this->uploadPart($r, $bucket, $key);
        }

        $b = $this->bucket($bucket);
        $p = $this->path($b, $key);
        File::ensureDirectoryExists(dirname($p));
        $source = $this->copySource($r);
        $temporaryUpload = null;

        if ($source) {
            $attributes = ['path' => $p, 'size' => $source->size, 'etag' => $source->etag, 'content_type' => $source->content_type, 'metadata' => $source->metadata, 'is_public' => $source->is_public, 'deleted_at' => null];
            $action = 'object.copy';
        } else {
            $temporaryUpload = $this->streamRequestToTemporaryPath($r, $p);
            $attributes = ['path' => $p, 'size' => $temporaryUpload['size'], 'etag' => $temporaryUpload['etag'], 'content_type' => $r->header('Content-Type'), 'metadata' => collect($r->headers->all())->filter(fn ($v, $k) => str_starts_with($k, 'x-amz-meta-'))->map(fn ($v) => $v[0])->all(), 'is_public' => $r->header('x-amz-acl') === 'public-read', 'deleted_at' => null];
            $action = 'object.put';
        }

        try {
            $o = DB::transaction(function () use ($b, $key, $attributes, $source, $p, $temporaryUpload) {
                $bucketModel = Bucket::query()->lockForUpdate()->findOrFail($b->id);
                $this->ensureWithinQuota($bucketModel, $key, $attributes['size']);
                File::copy($source ? $source->path : $temporaryUpload['path'], $p);

                return $bucketModel->objects()->updateOrCreate(['key' => $key], $attributes);
            });
        } finally {
            if ($temporaryUpload !== null) {
                File::delete($temporaryUpload['path']);
            }
        }
        $this->audit($r, $action, $bucket, $key);

        return response('', 200, ['ETag' => '"'.$o->etag.'"']);
    }

    public function get(Request $request, string $bucket, string $key)
    {
        $o = $this->bucket($bucket)->objects()->where('key', $key)->whereNull('deleted_at')->firstOrFail();
        if (! File::exists($o->path)) {
            abort(404);
        }

        $start = 0;
        $end = $o->size - 1;
        $status = 200;
        if ($request->header('Range')) {
            [$start, $end] = $this->range($request->header('Range'), $o->size);
            $status = 206;
        }

        $length = $end - $start + 1;
        $headers = ['Content-Type' => $o->content_type ?: 'application/octet-stream', 'Content-Length' => $length, 'ETag' => '"'.$o->etag.'"', 'Accept-Ranges' => 'bytes'];
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$o->size}";
        }

        return response()->stream(function () use ($o, $start, $length): void {
            $stream = fopen($o->path, 'rb');
            fseek($stream, $start);
            $remaining = $length;
            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, min(1024 * 1024, $remaining));
                $remaining -= strlen($chunk);
                echo $chunk;
            }
            fclose($stream);
        }, $status, $headers);
    }

    public function head(string $bucket, string $key)
    {
        $object = $this->bucket($bucket)->objects()->where('key', $key)->whereNull('deleted_at')->firstOrFail();

        return response('', 200, ['Content-Type' => $object->content_type ?: 'application/octet-stream', 'Content-Length' => $object->size, 'ETag' => '"'.$object->etag.'"', 'Last-Modified' => $object->updated_at->toRfc7231String()]);
    }

    public function delete(Request $r, string $bucket, string $key)
    {
        if ($r->filled('uploadId')) {
            return $this->abortMultipart($r, $bucket, $key);
        }

        $o = $this->bucket($bucket)->objects()->where('key', $key)->whereNull('deleted_at')->firstOrFail();
        $o->update(['deleted_at' => now()]);
        $this->audit($r, 'object.delete', $bucket, $key);

        return response('', 204);
    }

    public function post(Request $request, string $bucket, string $key)
    {
        if ($request->has('uploads')) {
            return $this->initiateMultipart($request, $bucket, $key);
        }

        return $this->completeMultipart($request, $bucket, $key);
    }

    public function deleteObjects(Request $request, string $bucket)
    {
        $xml = $this->deleteRequestXml($request->getContent());
        $keys = [];
        foreach ($xml->children() as $object) {
            if ($object->getName() === 'Object' && (string) $object->Key !== '') {
                $keys[] = (string) $object->Key;
            }
        }

        abort_if($keys === [] || count($keys) > 1000, 400, 'Delete request must contain between 1 and 1000 object keys.');

        $bucketModel = $this->bucket($bucket);
        $objects = $bucketModel->objects()
            ->whereIn('key', array_unique($keys))
            ->whereNull('deleted_at')
            ->get();
        $objects->each->update(['deleted_at' => now()]);
        foreach ($objects as $object) {
            $this->audit($request, 'object.delete', $bucket, $object->key);
        }

        return response()->view('s3.delete', [
            'keys' => $keys,
            'quiet' => strtolower((string) $xml->Quiet) === 'true',
        ], 200, ['Content-Type' => 'application/xml']);
    }

    private function deleteRequestXml(string $body): \SimpleXMLElement
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();

        abort_if($xml === false || $xml->getName() !== 'Delete', 400, 'Malformed DeleteObjects request.');

        return $xml;
    }

    private function initiateMultipart(Request $request, string $bucket, string $key)
    {
        $bucketModel = $this->bucket($bucket);
        $upload = $bucketModel->multipartUploads()->create(['key' => $key, 'upload_id' => (string) Str::uuid(), 'content_type' => $request->header('Content-Type'), 'metadata' => [], 'is_public' => $request->header('x-amz-acl') === 'public-read']);
        $this->audit($request, 'multipart.initiate', $bucket, $key);

        return response("<?xml version=\"1.0\" encoding=\"UTF-8\"?><InitiateMultipartUploadResult><Bucket>{$bucket}</Bucket><Key>{$key}</Key><UploadId>{$upload->upload_id}</UploadId></InitiateMultipartUploadResult>", 200, ['Content-Type' => 'application/xml']);
    }

    private function uploadPart(Request $request, string $bucket, string $key)
    {
        $upload = $this->multipartUpload($bucket, $key, $request->string('uploadId')->toString());
        $partNumber = $request->integer('partNumber');
        abort_unless($partNumber >= 1 && $partNumber <= 10000, 400, 'Invalid part number.');
        $path = config('object-store.root').DIRECTORY_SEPARATOR.'multipart'.DIRECTORY_SEPARATOR.$upload->upload_id.DIRECTORY_SEPARATOR.$partNumber;
        $temporaryPart = $this->streamRequestToTemporaryPath($request, $path);
        try {
            File::copy($temporaryPart['path'], $path);
        } finally {
            File::delete($temporaryPart['path']);
        }
        $part = $upload->parts()->updateOrCreate(['part_number' => $partNumber], ['path' => $path, 'size' => $temporaryPart['size'], 'etag' => $temporaryPart['etag']]);
        $this->audit($request, 'multipart.part.put', $bucket, $key);

        return response('', 200, ['ETag' => '"'.$part->etag.'"']);
    }

    private function completeMultipart(Request $request, string $bucket, string $key)
    {
        $upload = $this->multipartUpload($bucket, $key, $request->string('uploadId')->toString());
        $parts = $upload->parts()->orderBy('part_number')->get();
        abort_if($parts->isEmpty(), 400, 'Multipart upload has no parts.');
        $requestedParts = $this->completedParts($request->getContent());
        $storedParts = $parts->map(fn (MultipartPart $part) => ['number' => (int) $part->part_number, 'etag' => $part->etag])->all();
        abort_if($requestedParts !== $storedParts, 400, 'Multipart completion parts do not match uploaded parts.');
        $size = $parts->sum('size');
        $etag = DB::transaction(function () use ($bucket, $key, $size, $parts, $upload) {
            $bucketModel = Bucket::query()->lockForUpdate()->where('name', $bucket)->firstOrFail();
            $this->ensureWithinQuota($bucketModel, $key, $size);
            $path = $this->path($bucketModel, $key);
            File::ensureDirectoryExists(dirname($path));
            $destination = fopen($path, 'wb');
            foreach ($parts as $part) {
                $source = fopen($part->path, 'rb');
                stream_copy_to_stream($source, $destination);
                fclose($source);
            }
            fclose($destination);
            $etag = md5_file($path);
            $bucketModel->objects()->updateOrCreate(['key' => $key], ['path' => $path, 'size' => $size, 'etag' => $etag, 'content_type' => $upload->content_type, 'metadata' => $upload->metadata, 'is_public' => $upload->is_public, 'deleted_at' => null]);

            return $etag;
        });
        $this->removeMultipartFiles($upload);
        $upload->delete();
        $this->audit($request, 'multipart.complete', $bucket, $key);

        return response("<?xml version=\"1.0\" encoding=\"UTF-8\"?><CompleteMultipartUploadResult><Bucket>{$bucket}</Bucket><Key>{$key}</Key><ETag>\"{$etag}\"</ETag></CompleteMultipartUploadResult>", 200, ['Content-Type' => 'application/xml']);
    }

    private function abortMultipart(Request $request, string $bucket, string $key)
    {
        $upload = $this->multipartUpload($bucket, $key, $request->string('uploadId')->toString());
        $this->removeMultipartFiles($upload);
        $upload->delete();
        $this->audit($request, 'multipart.abort', $bucket, $key);

        return response('', 204);
    }

    private function multipartUpload(string $bucket, string $key, string $uploadId): MultipartUpload
    {
        return $this->bucket($bucket)->multipartUploads()->where('key', $key)->where('upload_id', $uploadId)->firstOrFail();
    }

    private function removeMultipartFiles(MultipartUpload $upload): void
    {
        File::deleteDirectory(config('object-store.root').DIRECTORY_SEPARATOR.'multipart'.DIRECTORY_SEPARATOR.$upload->upload_id);
    }

    private function copySource(Request $request): ?ObjectRecord
    {
        $copySource = $request->header('x-amz-copy-source');

        if (! $copySource) {
            return null;
        }

        [$bucket, $key] = explode('/', ltrim(urldecode($copySource), '/'), 2);

        return $this->bucket($bucket)->objects()->where('key', $key)->whereNull('deleted_at')->firstOrFail();
    }

    private function ensureWithinQuota(Bucket $bucket, string $key, int $incomingSize): void
    {
        if ($bucket->quota_bytes === null) {
            return;
        }

        $existingSize = $bucket->objects()->where('key', $key)->whereNull('deleted_at')->value('size') ?? 0;
        $usedBytes = $bucket->objects()->whereNull('deleted_at')->sum('size');

        if (($usedBytes - $existingSize + $incomingSize) > $bucket->quota_bytes) {
            abort(413, 'Bucket quota would be exceeded.');
        }
    }

    private function range(string $header, int $size): array
    {
        abort_unless(preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches), 416, 'Invalid byte range.');
        $start = $matches[1] === '' ? max($size - (int) $matches[2], 0) : (int) $matches[1];
        $end = $matches[2] === '' || $matches[1] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
        abort_if($size === 0 || $start > $end || $start >= $size, 416, 'Requested range is not satisfiable.');

        return [$start, $end];
    }

    private function completedParts(string $body): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        abort_if($xml === false || $xml->getName() !== 'CompleteMultipartUpload', 400, 'Malformed multipart completion request.');

        $parts = [];
        foreach ($xml->Part as $part) {
            $parts[] = ['number' => (int) $part->PartNumber, 'etag' => trim((string) $part->ETag, '"')];
        }

        return $parts;
    }

    private function streamRequestToTemporaryPath(Request $request, string $path): array
    {
        $temporaryPath = $path.'.'.Str::uuid().'.upload';
        File::ensureDirectoryExists(dirname($temporaryPath));
        $input = $request->getContent(true);
        $output = fopen($temporaryPath, 'wb');
        $hash = hash_init('md5');
        $size = 0;

        while (! feof($input)) {
            $chunk = fread($input, 1024 * 1024);
            $size += strlen($chunk);
            if ($size > config('object-store.max_bytes')) {
                fclose($output);
                File::delete($temporaryPath);
                abort(413, 'Object exceeds configured size.');
            }
            fwrite($output, $chunk);
            hash_update($hash, $chunk);
        }
        fclose($output);

        return ['path' => $temporaryPath, 'size' => $size, 'etag' => hash_final($hash)];
    }
}
