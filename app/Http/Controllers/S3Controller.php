<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Bucket;
use App\Models\ObjectRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

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
        $b = $this->bucket($bucket);
        $p = $this->path($b, $key);
        File::ensureDirectoryExists(dirname($p));
        $source = $this->copySource($r);

        if ($source) {
            $attributes = ['path' => $p, 'size' => $source->size, 'etag' => $source->etag, 'content_type' => $source->content_type, 'metadata' => $source->metadata, 'is_public' => $source->is_public, 'deleted_at' => null];
            $action = 'object.copy';
        } else {
            $body = $r->getContent();
            if (strlen($body) > config('object-store.max_bytes')) {
                abort(413, 'Object exceeds configured size.');
            }

            $attributes = ['path' => $p, 'size' => strlen($body), 'etag' => md5($body), 'content_type' => $r->header('Content-Type'), 'metadata' => collect($r->headers->all())->filter(fn ($v, $k) => str_starts_with($k, 'x-amz-meta-'))->map(fn ($v) => $v[0])->all(), 'is_public' => $r->header('x-amz-acl') === 'public-read', 'deleted_at' => null];
            $action = 'object.put';
        }

        $this->ensureWithinQuota($b, $key, $attributes['size']);

        if ($source) {
            File::copy($source->path, $p);
        } else {
            File::put($p, $body);
        }

        $o = $b->objects()->updateOrCreate(['key' => $key], $attributes);
        $this->audit($r, $action, $bucket, $key);

        return response('', 200, ['ETag' => '"'.$o->etag.'"']);
    }

    public function get(string $bucket, string $key)
    {
        $o = $this->bucket($bucket)->objects()->where('key', $key)->whereNull('deleted_at')->firstOrFail();
        if (! File::exists($o->path)) {
            abort(404);
        }

        return response()->file($o->path, ['Content-Type' => $o->content_type ?: 'application/octet-stream', 'ETag' => '"'.$o->etag.'"']);
    }

    public function head(string $bucket, string $key)
    {
        $object = $this->bucket($bucket)->objects()->where('key', $key)->whereNull('deleted_at')->firstOrFail();

        return response('', 200, ['Content-Type' => $object->content_type ?: 'application/octet-stream', 'Content-Length' => $object->size, 'ETag' => '"'.$object->etag.'"', 'Last-Modified' => $object->updated_at->toRfc7231String()]);
    }

    public function delete(Request $r, string $bucket, string $key)
    {
        $o = $this->bucket($bucket)->objects()->where('key', $key)->whereNull('deleted_at')->firstOrFail();
        $o->update(['deleted_at' => now()]);
        $this->audit($r, 'object.delete', $bucket, $key);

        return response('', 204);
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
}
