<?php

namespace Tests\Feature;

use App\Models\Bucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MultipartUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_parts_complete_into_one_object_and_cleanup_the_upload(): void
    {
        config(['object-store.root' => storage_path('framework/testing-multipart')]);
        File::deleteDirectory(config('object-store.root'));
        Bucket::create(['name' => 'media']);

        $initiate = $this->withoutMiddleware()->post('/s3/media/video.bin?uploads');
        preg_match('/<UploadId>([^<]+)<\/UploadId>/', $initiate->getContent(), $matches);
        $uploadId = $matches[1];

        $this->withoutMiddleware()->call('PUT', "/s3/media/video.bin?uploadId={$uploadId}&partNumber=1", [], [], [], [], 'hello ')->assertOk();
        $this->withoutMiddleware()->call('PUT', "/s3/media/video.bin?uploadId={$uploadId}&partNumber=2", [], [], [], [], 'world')->assertOk();
        $complete = $this->withoutMiddleware()->call('POST', "/s3/media/video.bin?uploadId={$uploadId}", [], [], [], [], '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"'.md5('hello ').'"</ETag></Part><Part><PartNumber>2</PartNumber><ETag>"'.md5('world').'"</ETag></Part></CompleteMultipartUpload>');

        $complete->assertOk()->assertSee('CompleteMultipartUploadResult');
        $this->assertDatabaseMissing('multipart_uploads', ['upload_id' => $uploadId]);
        $this->assertDatabaseHas('objects', ['key' => 'video.bin', 'size' => 11]);
        $this->assertSame('hello world', File::get(config('object-store.root').DIRECTORY_SEPARATOR.'1'.DIRECTORY_SEPARATOR.hash('sha256', 'video.bin')));
        File::deleteDirectory(config('object-store.root'));
    }
}
