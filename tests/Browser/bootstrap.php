<?php

use App\Models\AccessKey;
use App\Models\AuditEvent;
use App\Models\Bucket;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
User::create(['name' => 'Browser owner', 'email' => 'browser@example.test', 'password' => Hash::make('browser-smoke-password')]);
$media = Bucket::create(['name' => 'media', 'quota_bytes' => 10485760]);
$buckets = [$media, Bucket::create(['name' => 'documents', 'quota_bytes' => 104857600]), Bucket::create(['name' => 'site-backups', 'quota_bytes' => 1073741824])];
foreach ($buckets as $bucket) {
    for ($index = 0; $index < 3; $index++) {
        $key = ['images/cover.jpg', 'releases/readme.txt', 'archive/previous.zip'][$index];
        $bucket->objects()->create(['key' => $key, 'path' => storage_path('framework/browser-fixture-'.$bucket->id.'-'.$index), 'size' => [485760, 12480, 2097152][$index], 'etag' => md5($key), 'content_type' => ['image/jpeg', 'text/plain', 'application/zip'][$index], 'is_public' => $index === 0, 'deleted_at' => $index === 2 ? now() : null]);
    }
}
AccessKey::create(['access_key' => 'HBR_BROWSER_EXAMPLE', 'secret_encrypted' => 'unused-browser-fixture', 'scopes' => ['read', 'write', 'list'], 'bucket_names' => ['media']]);
foreach (['object.put', 'object.put', 'bucket.quota.update', 'object.delete', 'object.copy'] as $index => $action) {
    AuditEvent::create(['action' => $action, 'bucket' => 'media', 'object_key' => ['images/cover.jpg', 'releases/readme.txt', null, 'archive/previous.zip', 'images/cover-copy.jpg'][$index], 'actor' => 'browser@example.test', 'created_at' => now()->subMinutes(($index + 1) * 7)]);
}
