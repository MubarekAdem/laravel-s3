<?php

namespace App\Http\Controllers;

use App\Models\AccessKey;
use App\Models\AuditEvent;
use App\Models\Bucket;
use App\Models\ObjectRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DashboardController extends Controller
{
    public function index()
    {
        $filters = request()->validate(['activity_bucket' => ['nullable', 'string', 'max:255'], 'activity_action' => ['nullable', 'string', 'max:100'], 'activity_search' => ['nullable', 'string', 'max:255'], 'usage_bucket' => ['nullable', 'string', 'max:255']]);
        $bucketRows = Bucket::query()
            ->withCount(['objects as active_objects_count' => fn ($query) => $query->whereNull('deleted_at')])
            ->withSum(['objects as active_bytes' => fn ($query) => $query->whereNull('deleted_at')], 'size')
            ->orderBy('name')
            ->get();

        return view('dashboard', [
            'buckets' => $bucketRows->count(),
            'bucketRows' => $bucketRows,
            'usageRows' => empty($filters['usage_bucket']) ? $bucketRows : $bucketRows->where('name', $filters['usage_bucket']),
            'objects' => ObjectRecord::whereNull('deleted_at')->count(),
            'bytes' => (int) ObjectRecord::whereNull('deleted_at')->sum('size'),
            'keys' => AccessKey::whereNull('revoked_at')->latest()->get(),
            'events' => AuditEvent::query()->when($filters['activity_bucket'] ?? null, fn ($query, $bucket) => $query->where('bucket', $bucket))->when($filters['activity_action'] ?? null, fn ($query, $action) => $query->where('action', $action))->when($filters['activity_search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->where('actor', 'like', '%'.$search.'%')->orWhere('object_key', 'like', '%'.$search.'%')))->latest('id')->paginate(20, ['*'], 'activity_page')->withQueryString(),
            'activityFilters' => $filters,
        ]);
    }

    public function storeBucket(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:63', 'regex:/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/', 'unique:buckets,name'],
            'quota_mb' => ['nullable', 'integer', 'min:1', 'max:1048576'],
        ]);

        Bucket::create([
            'name' => $data['name'],
            'quota_bytes' => isset($data['quota_mb']) ? $data['quota_mb'] * 1024 * 1024 : null,
        ]);

        return back(fallback: route('dashboard'))->with('status', 'Bucket created.');
    }

    public function updateBucket(Request $request, Bucket $bucket)
    {
        $data = $request->validate(['quota_mb' => ['nullable', 'integer', 'min:1', 'max:1048576']]);
        DB::transaction(function () use ($bucket, $data): void {
            $lockedBucket = Bucket::query()->lockForUpdate()->findOrFail($bucket->id);
            $quota = isset($data['quota_mb']) ? $data['quota_mb'] * 1024 * 1024 : null;
            $used = (int) $lockedBucket->objects()->whereNull('deleted_at')->sum('size');
            if ($quota !== null && $quota < $used) {
                throw ValidationException::withMessages(['quota_mb' => 'Quota cannot be lower than the bytes currently stored.']);
            }
            $lockedBucket->update(['quota_bytes' => $quota]);
        });
        $this->auditOwner($request, 'bucket.quota.update', $bucket->name);

        return back(fallback: route('dashboard'))->with('status', 'Bucket quota updated.');
    }

    public function destroyBucket(Request $request, Bucket $bucket)
    {
        abort_if($bucket->objects()->exists() || $bucket->multipartUploads()->exists(), 409, 'Bucket must be empty before it can be deleted.');
        File::deleteDirectory(config('object-store.root').DIRECTORY_SEPARATOR.$bucket->id);
        $bucketName = $bucket->name;
        $bucket->delete();
        $this->auditOwner($request, 'bucket.delete', $bucketName);

        return redirect()->route('dashboard')->with('status', 'Bucket deleted.');
    }

    public function showBucket(Request $request, Bucket $bucket)
    {
        $data = $request->validate(['prefix' => ['nullable', 'string', 'max:1024'], 'state' => ['nullable', 'in:active,trash']]);
        $prefix = $data['prefix'] ?? '';
        $state = $data['state'] ?? 'active';
        $objects = $bucket->objects()
            ->when($state === 'active', fn ($query) => $query->whereNull('deleted_at'), fn ($query) => $query->whereNotNull('deleted_at'))
            ->when($prefix !== '', fn ($query) => $query->where('key', 'like', $prefix.'%'))
            ->orderBy('key')
            ->paginate(50)
            ->withQueryString();

        return view('buckets.show', [
            'bucket' => $bucket,
            'objects' => $objects,
            'prefix' => $prefix,
            'state' => $state,
            'usedBytes' => (int) $bucket->objects()->whereNull('deleted_at')->sum('size'),
        ]);
    }

    public function downloadObject(Bucket $bucket, ObjectRecord $object)
    {
        abort_if($object->deleted_at !== null, 404);
        abort_unless(File::exists($object->path), 404);

        $stream = fopen($object->path, 'rb');
        abort_if($stream === false, 404);

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, basename($object->key), [
            'Content-Type' => $object->content_type ?: 'application/octet-stream',
            'Content-Length' => $object->size,
        ]);
    }

    public function destroyObject(Request $request, Bucket $bucket, ObjectRecord $object)
    {
        $object->update(['deleted_at' => now()]);
        $this->auditOwner($request, 'object.delete', $bucket->name, $object->key);

        return back(fallback: route('dashboard'))->with('status', 'Object moved to trash.');
    }

    public function restoreObject(Request $request, Bucket $bucket, ObjectRecord $object)
    {
        DB::transaction(function () use ($bucket, $object): void {
            $lockedBucket = Bucket::query()->lockForUpdate()->findOrFail($bucket->id);
            $storedObject = $lockedBucket->objects()->findOrFail($object->id);
            if ($storedObject->deleted_at !== null) {
                $used = (int) $lockedBucket->objects()->whereNull('deleted_at')->sum('size');
                abort_if($lockedBucket->quota_bytes !== null && $used + $storedObject->size > $lockedBucket->quota_bytes, 409, 'Restoring this object would exceed the bucket quota.');
                $storedObject->update(['deleted_at' => null]);
            }
        });
        $this->auditOwner($request, 'object.restore', $bucket->name, $object->key);

        return back(fallback: route('dashboard'))->with('status', 'Object restored.');
    }

    public function purgeObject(Request $request, Bucket $bucket, ObjectRecord $object)
    {
        $request->validate(['confirm_purge' => ['accepted']]);
        $key = $object->key;
        DB::transaction(function () use ($bucket, $object): void {
            Bucket::query()->lockForUpdate()->findOrFail($bucket->id);
            $storedObject = $bucket->objects()->findOrFail($object->id);
            abort_unless($storedObject->deleted_at !== null, 409, 'Only trashed objects can be purged.');
            $expectedPath = config('object-store.root').DIRECTORY_SEPARATOR.$bucket->id.DIRECTORY_SEPARATOR.hash('sha256', $storedObject->key);
            abort_unless($storedObject->path === $expectedPath, 409, 'Object storage path does not match the expected location.');
            abort_if(File::exists($storedObject->path) && ! File::delete($storedObject->path), 500, 'Object file could not be deleted.');
            $storedObject->delete();
        });
        $this->auditOwner($request, 'object.purge', $bucket->name, $key);

        return back(fallback: route('dashboard'))->with('status', 'Object permanently deleted.');
    }

    public function key(Request $r)
    {
        $data = $r->validate(['scopes' => ['required', 'array', 'min:1'], 'scopes.*' => ['in:read,write,delete,list'], 'bucket_names' => ['array'], 'bucket_names.*' => ['string', 'exists:buckets,name']]);
        $secret = Str::random(40);
        $key = AccessKey::create(['access_key' => 'HBR'.strtoupper(Str::random(17)), 'secret_encrypted' => Crypt::encryptString($secret), 'scopes' => $data['scopes'] ?? ['*'], 'bucket_names' => $data['bucket_names'] ?? []]);

        return back(fallback: route('dashboard'))->with('created_secret', [$key->access_key, $secret]);
    }

    public function revoke(AccessKey $key)
    {
        $key->update(['revoked_at' => now()]);

        return back(fallback: route('dashboard'));
    }

    public function updateKey(Request $request, AccessKey $key)
    {
        $data = $request->validate(['scopes' => ['required', 'array', 'min:1'], 'scopes.*' => ['in:read,write,delete,list'], 'bucket_names' => ['array'], 'bucket_names.*' => ['string', 'exists:buckets,name']]);
        $key->update(['scopes' => $data['scopes'], 'bucket_names' => $data['bucket_names'] ?? []]);

        return back(fallback: route('dashboard'))->with('status', 'Credential permissions updated.');
    }

    private function auditOwner(Request $request, string $action, ?string $bucket = null, ?string $key = null): void
    {
        AuditEvent::create(['actor' => $request->user()?->email, 'action' => $action, 'bucket' => $bucket, 'object_key' => $key, 'ip' => $request->ip(), 'context' => ['method' => $request->method(), 'source' => 'owner-dashboard']]);
    }
}
