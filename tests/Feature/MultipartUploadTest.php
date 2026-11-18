<?php

namespace Tests\Feature;

use App\Models\Bucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MultipartUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_completion_can_select_a_subset_and_returns_a_multipart_etag(): void
    {
        $root = storage_path('framework/testing-multipart-subset');
        config(['object-store.root' => $root]);
        $bucket = Bucket::create(['name' => 'media']);
        $upload = $bucket->multipartUploads()->create(['key' => 'selected.bin', 'upload_id' => 'subset-upload']);

        try {
            $this->withoutMiddleware()->call('PUT', '/s3/media/selected.bin?uploadId=subset-upload&partNumber=1', [], [], [], [], 'selected')->assertOk();
            $this->withoutMiddleware()->call('PUT', '/s3/media/selected.bin?uploadId=subset-upload&partNumber=2', [], [], [], [], 'unused')->assertOk();
            $response = $this->withoutMiddleware()->call('POST', '/s3/media/selected.bin?uploadId=subset-upload', [], [], [], [], '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'.md5('selected').'</ETag></Part></CompleteMultipartUpload>');

            $response->assertOk();
            $object = $bucket->objects()->firstOrFail();
            $this->assertSame('selected', File::get($object->path));
            $this->assertStringEndsWith('-1', $object->etag);
            $this->assertModelMissing($upload);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_invalid_completion_preserves_the_upload_and_existing_object(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $object = $bucket->objects()->create(['key' => 'existing.bin', 'path' => 'existing', 'size' => 8, 'etag' => md5('original')]);
        $upload = $bucket->multipartUploads()->create(['key' => 'existing.bin', 'upload_id' => 'invalid-upload']);
        $upload->parts()->create(['part_number' => 1, 'path' => 'missing-part', 'size' => 3, 'etag' => md5('new')]);

        $this->withoutMiddleware()->call('POST', '/s3/media/existing.bin?uploadId=invalid-upload', [], [], [], [], '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>wrong</ETag></Part></CompleteMultipartUpload>')->assertBadRequest();

        $this->assertSame(md5('original'), $object->fresh()->etag);
        $this->assertModelExists($upload);
    }

    public function test_cleanup_retains_recently_active_uploads(): void
    {
        $this->freezeTime();
        $root = storage_path('framework/testing-multipart-expiry');
        config(['object-store.root' => $root, 'object-store.multipart_hours' => 24]);
        $bucket = Bucket::create(['name' => 'media']);
        $expired = $bucket->multipartUploads()->create(['key' => 'expired', 'upload_id' => 'expired-upload']);
        $expired->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();
        $active = $bucket->multipartUploads()->create(['key' => 'active', 'upload_id' => 'active-upload']);
        $active->forceFill(['created_at' => now()->subDays(2), 'updated_at' => now()])->save();
        $directory = $root.DIRECTORY_SEPARATOR.'multipart'.DIRECTORY_SEPARATOR.'expired-upload';
        File::ensureDirectoryExists($directory);
        File::put($directory.DIRECTORY_SEPARATOR.'1', 'expired');

        try {
            $this->artisan('object-store:purge-multipart')->assertExitCode(0);

            $this->assertModelMissing($expired);
            $this->assertModelExists($active);
            $this->assertFalse(File::exists($directory));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_parts_complete_into_one_object_and_cleanup_the_upload(): void
    {
        config(['object-store.root' => storage_path('framework/testing-multipart')]);
        File::deleteDirectory(config('object-store.root'));
        Bucket::create(['name' => 'media']);

        $initiate = $this->withoutMiddleware()->post('/s3/media/video.bin?uploads');
        preg_match('/<UploadId>([^<]+)<\/UploadId>/', $initiate->getContent(), $matches);
        $uploadId = $matches[1];
        $firstPart = str_repeat('a', 5 * 1024 * 1024);

        $this->withoutMiddleware()->call('PUT', "/s3/media/video.bin?uploadId={$uploadId}&partNumber=1", [], [], [], [], $firstPart)->assertOk();
        $this->withoutMiddleware()->call('PUT', "/s3/media/video.bin?uploadId={$uploadId}&partNumber=2", [], [], [], [], 'world')->assertOk();
        $complete = $this->withoutMiddleware()->call('POST', "/s3/media/video.bin?uploadId={$uploadId}", [], [], [], [], '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"'.md5($firstPart).'"</ETag></Part><Part><PartNumber>2</PartNumber><ETag>"'.md5('world').'"</ETag></Part></CompleteMultipartUpload>');

        $complete->assertOk()->assertSee('CompleteMultipartUploadResult');
        $this->assertDatabaseMissing('multipart_uploads', ['upload_id' => $uploadId]);
        $this->assertDatabaseHas('objects', ['key' => 'video.bin', 'size' => 5242885]);
        $this->assertSame($firstPart.'world', File::get(config('object-store.root').DIRECTORY_SEPARATOR.'1'.DIRECTORY_SEPARATOR.hash('sha256', 'video.bin')));
        File::deleteDirectory(config('object-store.root'));
    }
}
