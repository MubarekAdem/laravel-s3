<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyS3Signature;
use App\Models\Bucket;
use App\Models\MultipartPart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MultipartLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_part_replacement_failure_preserves_the_previous_part(): void
    {
        $root = storage_path('framework/testing-multipart-replacement');
        config(['object-store.root' => $root]);
        $bucket = Bucket::create(['name' => 'media']);
        $upload = $bucket->multipartUploads()->create(['key' => 'file', 'upload_id' => 'replace-upload']);
        $this->withoutMiddleware();
        try {
            $this->call('PUT', '/s3/media/file?uploadId=replace-upload&partNumber=1', [], [], [], [], 'original')->assertOk();
            $part = $upload->parts()->firstOrFail();
            $dispatcher = MultipartPart::getEventDispatcher();
            MultipartPart::setEventDispatcher(clone $dispatcher);
            MultipartPart::saving(function (): void {
                throw new \RuntimeException('Simulated part save failure');
            });
            try {
                $this->call('PUT', '/s3/media/file?uploadId=replace-upload&partNumber=1', [], [], [], [], 'replacement')->assertServerError();
            } finally {
                MultipartPart::setEventDispatcher($dispatcher);
            }
            $this->assertSame('original', File::get($part->fresh()->path));
            $this->assertSame(md5('original'), $part->fresh()->etag);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_nonfinal_parts_must_meet_the_minimum_size(): void
    {
        $root = storage_path('framework/testing-multipart-minimum');
        config(['object-store.root' => $root]);
        $bucket = Bucket::create(['name' => 'media']);
        $upload = $bucket->multipartUploads()->create(['key' => 'tiny', 'upload_id' => 'minimum-upload']);
        $this->withoutMiddleware(VerifyS3Signature::class);
        try {
            foreach ([1 => 'first', 2 => 'last'] as $number => $content) {
                $this->call('PUT', '/s3/media/tiny?uploadId=minimum-upload&partNumber='.$number, [], [], [], [], $content)->assertOk();
            }
            $xml = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'.md5('first').'</ETag></Part><Part><PartNumber>2</PartNumber><ETag>'.md5('last').'</ETag></Part></CompleteMultipartUpload>';
            $this->call('POST', '/s3/media/tiny?uploadId=minimum-upload', [], [], [], [], $xml)->assertBadRequest()->assertSee('EntityTooSmall');
            $this->assertDatabaseCount('objects', 0);
            $this->assertModelExists($upload);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_pending_recovery_prevents_cleanup_and_new_writes(): void
    {
        $root = storage_path('framework/testing-pending-recovery');
        config(['object-store.root' => $root]);
        File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.'.recovery');
        File::put($root.DIRECTORY_SEPARATOR.'.recovery'.DIRECTORY_SEPARATOR.'pending.json', '{}');
        try {
            $this->withoutMiddleware(VerifyS3Signature::class)->put('/s3/media/new')->assertStatus(503)->assertSee('ServiceUnavailable');
            $this->artisan('object-store:purge')->assertExitCode(1);
            $this->artisan('object-store:purge-multipart')->assertExitCode(1);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
