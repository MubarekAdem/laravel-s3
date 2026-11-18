<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Bucket;
use App\Models\MultipartUpload;
use App\Models\ObjectRecord;
use App\Services\ObjectPublisher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class S3Controller extends Controller
{
    public function __construct(private ObjectPublisher $publisher) {}

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
        AuditEvent::create(['request_id' => $r->attributes->get('s3_request_id'), 'actor' => optional($r->attributes->get('s3_key'))->access_key, 'action' => $action, 'bucket' => $bucket, 'object_key' => $key, 'ip' => $r->ip(), 'context' => ['method' => $r->method()]]);
    }

    public function buckets()
    {
        $allowed = request()->attributes->get('s3_key')?->bucket_names ?? [];

        return $this->xml(['buckets' => Bucket::query()->when($allowed !== [], fn ($query) => $query->whereIn('name', $allowed))->orderBy('name')->get()]);
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

        if ($bucketModel->objects()->exists() || $bucketModel->multipartUploads()->exists()) {
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
        if ($r->has('location')) {
            return response()->view('s3.location', ['region' => config('object-store.region')], 200, ['Content-Type' => 'application/xml']);
        }
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

        try {
            if ($source) {
                abort_if(File::size($source->path) > config('object-store.max_bytes'), 413, 'Object exceeds configured size.');
                $temporaryUpload = ['path' => $p.'.'.Str::uuid().'.upload'];
                abort_unless(File::copy($source->path, $temporaryUpload['path']), 500, 'Copy source could not be staged.');
                $attributes = ['size' => $source->size, 'etag' => $source->etag, 'content_type' => $source->content_type, 'metadata' => $source->metadata, 'is_public' => $source->is_public, 'deleted_at' => null];
                $action = 'object.copy';
            } else {
                $temporaryUpload = $this->streamRequestToTemporaryPath($r, $p);
                $attributes = ['size' => $temporaryUpload['size'], 'etag' => $temporaryUpload['etag'], 'content_type' => $r->header('Content-Type'), 'metadata' => collect($r->headers->all())->filter(fn ($v, $k) => str_starts_with($k, 'x-amz-meta-'))->map(fn ($v) => $v[0])->all(), 'is_public' => $r->header('x-amz-acl') === 'public-read', 'deleted_at' => null];
                $action = 'object.put';
            }
            $o = $this->publisher->publish($b, $key, $temporaryUpload['path'], $attributes);
        } finally {
            if ($temporaryUpload !== null) {
                $this->publisher->discardStage($temporaryUpload['path']);
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

        $this->checkPreconditions($request, $o);
        $validator = response('', 200, $this->objectHeaders($o));
        if ($validator->isNotModified($request)) {
            return $validator;
        }

        $start = 0;
        $end = $o->size - 1;
        $status = 200;
        $ifRange = $request->header('If-Range');
        $rangeAllowed = $ifRange === null || $ifRange === '"'.$o->etag.'"' || $ifRange === $o->updated_at->toRfc7231String();
        if ($request->header('Range') && $rangeAllowed) {
            [$start, $end] = $this->range($request->header('Range'), $o->size);
            $status = 206;
        }

        $length = $end - $start + 1;
        $headers = array_merge($this->objectHeaders($o), ['Content-Length' => $length, 'Accept-Ranges' => 'bytes']);
        if ($status === 206) {
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$o->size}";
        }

        $stream = fopen($o->path, 'rb');
        abort_if($stream === false, 500, 'Object stream could not be opened.');

        return response()->stream(function () use ($stream, $start, $length): void {
            try {
                abort_if(fseek($stream, $start) !== 0, 500, 'Object stream could not be positioned.');
                $remaining = $length;
                while ($remaining > 0) {
                    $chunk = fread($stream, min(1024 * 1024, $remaining));
                    abort_if($chunk === false || $chunk === '', 500, 'Object stream ended before its recorded size.');
                    $remaining -= strlen($chunk);
                    echo $chunk;
                }
            } finally {
                fclose($stream);
            }
        }, $status, $headers);
    }

    public function head(Request $request, string $bucket, string $key)
    {
        $object = $this->bucket($bucket)->objects()->where('key', $key)->whereNull('deleted_at')->firstOrFail();
        abort_unless(File::isFile($object->path), 404);

        $this->checkPreconditions($request, $object);
        $response = response('', 200, array_merge($this->objectHeaders($object), ['Content-Length' => $object->size, 'Accept-Ranges' => 'bytes']));
        $response->isNotModified($request);

        return $response;
    }

    private function objectHeaders(ObjectRecord $object): array
    {
        $headers = ['Content-Type' => $object->content_type ?: 'application/octet-stream', 'ETag' => '"'.$object->etag.'"', 'Last-Modified' => $object->updated_at->toRfc7231String()];
        foreach ($object->metadata ?? [] as $name => $value) {
            if (str_starts_with(strtolower($name), 'x-amz-meta-')) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function checkPreconditions(Request $request, ObjectRecord $object): void
    {
        $ifMatch = $request->header('If-Match');
        if ($ifMatch !== null) {
            $tags = array_map('trim', explode(',', $ifMatch));
            abort_unless(in_array('*', $tags, true) || in_array('"'.$object->etag.'"', $tags, true), 412, 'Object ETag does not match.');
        } elseif ($request->header('If-Unmodified-Since')) {
            $timestamp = strtotime($request->header('If-Unmodified-Since'));
            abort_if($timestamp !== false && $object->updated_at->getTimestamp() > $timestamp, 412, 'Object has been modified.');
        }
    }

    public function delete(Request $r, string $bucket, string $key)
    {
        if ($r->filled('uploadId')) {
            return $this->abortMultipart($r, $bucket, $key);
        }

        $o = $this->bucket($bucket)->objects()->where('key', $key)->whereNull('deleted_at')->first();
        if ($o !== null) {
            $o->update(['deleted_at' => now()]);
            $this->audit($r, 'object.delete', $bucket, $key);
        }

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

        return response()->view('s3.multipart', ['operation' => 'initiate', 'bucketName' => $bucket, 'objectKey' => $key, 'uploadId' => $upload->upload_id], 200, ['Content-Type' => 'application/xml']);
    }

    private function uploadPart(Request $request, string $bucket, string $key)
    {
        $upload = $this->multipartUpload($bucket, $key, $request->string('uploadId')->toString());
        $partNumber = $request->integer('partNumber');
        abort_unless($partNumber >= 1 && $partNumber <= 10000, 400, 'Invalid part number.');
        $directory = config('object-store.root').DIRECTORY_SEPARATOR.'multipart'.DIRECTORY_SEPARATOR.$upload->upload_id;
        $path = $directory.DIRECTORY_SEPARATOR.$partNumber.'.'.Str::uuid().'.part';
        $previousPath = $upload->parts()->where('part_number', $partNumber)->value('path');
        $temporaryPart = $this->streamRequestToTemporaryPath($request, $path);
        try {
            abort_unless(File::move($temporaryPart['path'], $path), 500, 'Multipart part could not be stored.');
            $part = DB::transaction(function () use ($upload, $partNumber, $path, $temporaryPart) {
                $part = $upload->parts()->updateOrCreate(['part_number' => $partNumber], ['path' => $path, 'size' => $temporaryPart['size'], 'etag' => $temporaryPart['etag']]);
                $upload->touch();

                return $part;
            });
        } catch (\Throwable $exception) {
            File::delete($path);
            throw $exception;
        } finally {
            File::delete($temporaryPart['path']);
        }
        if ($previousPath !== null && dirname($previousPath) === $directory && ! is_link($previousPath)) {
            File::delete($previousPath);
        }
        $this->audit($request, 'multipart.part.put', $bucket, $key);

        return response('', 200, ['ETag' => '"'.$part->etag.'"']);
    }

    private function completeMultipart(Request $request, string $bucket, string $key)
    {
        $upload = $this->multipartUpload($bucket, $key, $request->string('uploadId')->toString());
        $parts = $upload->parts()->orderBy('part_number')->get();
        abort_if($parts->isEmpty(), 400, 'Multipart upload has no parts.');
        $requestedParts = $this->completedParts($request->getContent());
        abort_if($requestedParts === [] || count($requestedParts) > 10000, 400, 'Invalid multipart part count.');
        $availableParts = $parts->keyBy('part_number');
        $selectedParts = collect();
        $previousNumber = 0;
        foreach ($requestedParts as $requestedPart) {
            abort_if($requestedPart['number'] <= $previousNumber, 400, 'Multipart parts must be in ascending order.');
            $part = $availableParts->get($requestedPart['number']);
            abort_if($part === null || ! hash_equals($part->etag, $requestedPart['etag']), 400, 'Multipart completion parts do not match uploaded parts.');
            abort_unless(File::isFile($part->path), 400, 'Multipart part file is missing.');
            $selectedParts->push($part);
            $previousNumber = $requestedPart['number'];
        }
        $parts = $selectedParts;
        foreach ($parts->slice(0, -1) as $part) {
            if ($part->size < 5 * 1024 * 1024) {
                return response()->view('s3.error', ['code' => 'EntityTooSmall', 'message' => 'Every part except the last must contain at least 5 MiB.'], 400, ['Content-Type' => 'application/xml']);
            }
        }
        $size = $parts->sum('size');
        abort_if($size > config('object-store.max_bytes'), 413, 'Object exceeds configured size.');
        $bucketModel = $this->bucket($bucket);
        $path = $this->path($bucketModel, $key);
        File::ensureDirectoryExists(dirname($path));
        $temporaryPath = $path.'.'.Str::uuid().'.assembly';
        $destination = fopen($temporaryPath, 'wb');
        abort_if($destination === false, 500, 'Multipart staging file could not be opened.');
        try {
            foreach ($parts as $part) {
                $source = fopen($part->path, 'rb');
                abort_if($source === false, 500, 'Multipart part could not be opened.');
                try {
                    abort_if(stream_copy_to_stream($source, $destination, (int) $part->size + 1) !== (int) $part->size, 500, 'Multipart part could not be assembled.');
                } finally {
                    fclose($source);
                }
            }
            abort_unless(fflush($destination), 500, 'Multipart stage could not be flushed.');
            fclose($destination);
            $destination = null;
            $etag = md5($parts->map(fn ($part) => hex2bin($part->etag))->implode('')).'-'.$parts->count();
            $this->publisher->publish($bucketModel, $key, $temporaryPath, ['size' => $size, 'etag' => $etag, 'content_type' => $upload->content_type, 'metadata' => $upload->metadata, 'is_public' => $upload->is_public, 'deleted_at' => null]);
        } finally {
            if (is_resource($destination)) {
                fclose($destination);
            }
            $this->publisher->discardStage($temporaryPath);
        }
        $this->removeMultipartFiles($upload);
        $upload->delete();
        $this->audit($request, 'multipart.complete', $bucket, $key);

        return response()->view('s3.multipart', ['operation' => 'complete', 'bucketName' => $bucket, 'objectKey' => $key, 'etag' => $etag], 200, ['Content-Type' => 'application/xml']);
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

        $sourceParts = explode('/', ltrim(rawurldecode($copySource), '/'), 2);
        abort_if(count($sourceParts) !== 2 || $sourceParts[0] === '' || $sourceParts[1] === '', 400, 'Invalid copy source.');
        [$bucket, $key] = $sourceParts;
        $credential = $request->attributes->get('s3_key');
        if ($credential !== null) {
            $scopes = $credential->scopes ?? [];
            $allowedBuckets = $credential->bucket_names ?? [];
            abort_unless((in_array('*', $scopes, true) || in_array('read', $scopes, true)) && ($allowedBuckets === [] || in_array($bucket, $allowedBuckets, true)), 403, 'Copy source access denied.');
        }

        return $this->bucket($bucket)->objects()->where('key', $key)->whereNull('deleted_at')->firstOrFail();
    }

    private function range(string $header, int $size): array
    {
        abort_unless(preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches), 416, 'Invalid byte range.');
        abort_if($matches[1] === '' && ($matches[2] === '' || (int) $matches[2] === 0), 416, 'Invalid byte range.');
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
        abort_if($output === false, 500, 'Upload stage could not be opened.');
        $hash = hash_init('md5');
        $size = 0;

        try {
            while (! feof($input)) {
                $chunk = fread($input, 1024 * 1024);
                abort_if($chunk === false || ($chunk === '' && ! feof($input)), 500, 'Upload stream could not be read.');
                $size += strlen($chunk);
                abort_if($size > config('object-store.max_bytes'), 413, 'Object exceeds configured size.');
                abort_if(fwrite($output, $chunk) !== strlen($chunk), 500, 'Upload stage could not be written.');
                hash_update($hash, $chunk);
            }
            abort_unless(fflush($output), 500, 'Upload stage could not be flushed.');
        } catch (\Throwable $exception) {
            fclose($output);
            File::delete($temporaryPath);
            throw $exception;
        }
        fclose($output);

        return ['path' => $temporaryPath, 'size' => $size, 'etag' => hash_final($hash)];
    }
}
