<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyS3Signature;
use App\Models\Bucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StorageProtocolTest extends TestCase
{
    use RefreshDatabase;

    public function test_head_bucket_and_range_get_follow_s3_semantics(): void
    {
        $root = storage_path('framework/testing-protocol');
        config(['object-store.root' => $root]);
        $bucket = Bucket::create(['name' => 'media']);
        $path = $root.DIRECTORY_SEPARATOR.$bucket->id.DIRECTORY_SEPARATOR.hash('sha256', 'video.txt');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, 'abcdef');
        $bucket->objects()->create(['key' => 'video.txt', 'path' => $path, 'size' => 6, 'etag' => md5('abcdef')]);

        $this->withoutMiddleware()->call('HEAD', '/s3/media')->assertOk();
        $this->withoutMiddleware()->get('/s3/media/video.txt', ['Range' => 'bytes=1-3'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 1-3/6')->assertStreamedContent('bcd');

        File::deleteDirectory($root);
    }

    public function test_s3_authentication_failures_return_xml_errors(): void
    {
        $this->get('/s3/media/video.txt')->assertForbidden()->assertHeader('Content-Type', 'application/xml')->assertSee('<Code>AccessDenied</Code>', false);
    }

    public function test_missing_s3_objects_return_xml_errors(): void
    {
        Bucket::create(['name' => 'media']);

        $this->withoutMiddleware(VerifyS3Signature::class)->get('/s3/media/missing.txt')->assertNotFound()->assertHeader('Content-Type', 'application/xml')->assertSee('<Code>NoSuchKey</Code>', false);
    }
}
