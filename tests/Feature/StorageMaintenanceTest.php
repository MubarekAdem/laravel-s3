<?php

namespace Tests\Feature;

use App\Models\Bucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StorageMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_retention_dry_run_preserves_files_and_real_run_only_removes_expired_trash(): void
    {
        $this->freezeTime();
        $root = storage_path('framework/testing-maintenance');
        config(['object-store.root' => $root, 'object-store.trash_days' => 30]);
        $bucket = Bucket::create(['name' => 'media']);
        $path = $root.DIRECTORY_SEPARATOR.$bucket->id.DIRECTORY_SEPARATOR.hash('sha256', 'expired');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'old');
        $expired = $bucket->objects()->create(['key' => 'expired', 'path' => $path, 'size' => 3, 'etag' => md5('old'), 'deleted_at' => now()->subDays(31)]);
        $recent = $bucket->objects()->create(['key' => 'recent', 'path' => 'recent', 'size' => 3, 'etag' => md5('new'), 'deleted_at' => now()->subDays(1)]);

        try {
            $this->artisan('object-store:purge --dry-run')->expectsOutput('Would purge 1 object(s).')->assertExitCode(0);
            $this->assertTrue(File::exists($path));
            $this->assertModelExists($expired);
            $this->artisan('object-store:purge')->assertExitCode(0);
            $this->assertModelMissing($expired);
            $this->assertModelExists($recent);
            $this->assertFalse(File::exists($path));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_retention_refuses_unexpected_object_paths(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $object = $bucket->objects()->create(['key' => 'unsafe', 'path' => 'unexpected-path', 'size' => 1, 'etag' => md5('x'), 'deleted_at' => now()->subDays(40)]);

        $this->artisan('object-store:purge')->assertExitCode(1);

        $this->assertModelExists($object);
    }

    public function test_installation_check_reports_invalid_configuration(): void
    {
        config(['object-store.max_bytes' => 0]);

        $this->artisan('object-store:check')->assertExitCode(1);
    }

    public function test_multipart_dry_run_preserves_expired_uploads(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $upload = $bucket->multipartUploads()->create(['key' => 'pending', 'upload_id' => 'dry-run-upload']);
        $upload->forceFill(['updated_at' => now()->subDays(2)])->save();

        $this->artisan('object-store:purge-multipart --dry-run')->expectsOutput('Would purge 1 multipart upload(s).')->assertExitCode(0);

        $this->assertModelExists($upload);
    }
}
