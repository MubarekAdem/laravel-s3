<?php

namespace App\Http\Controllers;

use App\Models\AccessKey;
use App\Models\AuditEvent;
use App\Models\Bucket;
use App\Models\ObjectRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class DashboardController extends Controller
{
    public function index()
    {
        $bucketRows = Bucket::query()
            ->withCount(['objects as active_objects_count' => fn ($query) => $query->whereNull('deleted_at')])
            ->withSum(['objects as active_bytes' => fn ($query) => $query->whereNull('deleted_at')], 'size')
            ->orderBy('name')
            ->get();

        return view('dashboard', [
            'buckets' => $bucketRows->count(),
            'bucketRows' => $bucketRows,
            'objects' => ObjectRecord::whereNull('deleted_at')->count(),
            'bytes' => (int) ObjectRecord::whereNull('deleted_at')->sum('size'),
            'keys' => AccessKey::whereNull('revoked_at')->latest()->get(),
            'events' => AuditEvent::latest()->limit(8)->get(),
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

        return back()->with('status', 'Bucket created.');
    }

    public function showBucket(Request $request, Bucket $bucket)
    {
        $data = $request->validate(['prefix' => ['nullable', 'string', 'max:1024']]);
        $prefix = $data['prefix'] ?? '';
        $objects = $bucket->objects()
            ->whereNull('deleted_at')
            ->when($prefix !== '', fn ($query) => $query->where('key', 'like', $prefix.'%'))
            ->orderBy('key')
            ->paginate(50)
            ->withQueryString();

        return view('buckets.show', [
            'bucket' => $bucket,
            'objects' => $objects,
            'prefix' => $prefix,
            'usedBytes' => (int) $bucket->objects()->whereNull('deleted_at')->sum('size'),
        ]);
    }

    public function downloadObject(Bucket $bucket, ObjectRecord $object)
    {
        abort_unless(File::exists($object->path), 404);

        return response()->download($object->path, basename($object->key), [
            'Content-Type' => $object->content_type ?: 'application/octet-stream',
        ]);
    }

    public function destroyObject(Request $request, Bucket $bucket, ObjectRecord $object)
    {
        $object->update(['deleted_at' => now()]);
        AuditEvent::create([
            'actor' => $request->user()?->email,
            'action' => 'object.delete',
            'bucket' => $bucket->name,
            'object_key' => $object->key,
            'ip' => $request->ip(),
            'context' => ['method' => $request->method(), 'source' => 'owner-dashboard'],
        ]);

        return back()->with('status', 'Object moved to trash.');
    }

    public function key(Request $r)
    {
        $data = $r->validate(['scopes' => ['array'], 'bucket_names' => ['array'], 'bucket_names.*' => ['string', 'exists:buckets,name']]);
        $secret = Str::random(40);
        $key = AccessKey::create(['access_key' => 'HBR'.strtoupper(Str::random(17)), 'secret_encrypted' => Crypt::encryptString($secret), 'scopes' => $data['scopes'] ?? ['*'], 'bucket_names' => $data['bucket_names'] ?? []]);

        return back()->with('created_secret', [$key->access_key, $secret]);
    }

    public function revoke(AccessKey $key)
    {
        $key->update(['revoked_at' => now()]);

        return back();
    }
}
