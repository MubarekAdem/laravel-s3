<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyS3Signature;
use App\Models\Bucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class S3CompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_object_deletion_succeeds_without_repeating_the_audit_event(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $object = $bucket->objects()->create(['key' => 'report', 'path' => 'report', 'size' => 1, 'etag' => md5('r')]);
        $this->withoutMiddleware(VerifyS3Signature::class);

        $this->delete('/s3/media/report')->assertNoContent()->assertHeader('x-amz-request-id');
        $deletedAt = $object->fresh()->deleted_at;
        $this->travel(1)->hours();
        $this->delete('/s3/media/report')->assertNoContent();
        $this->delete('/s3/media/missing')->assertNoContent();

        $this->assertSame($deletedAt->toAtomString(), $object->fresh()->deleted_at->toAtomString());
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_missing_resources_have_distinct_error_codes(): void
    {
        Bucket::create(['name' => 'media']);
        $this->withoutMiddleware(VerifyS3Signature::class);

        $this->get('/s3/absent')->assertNotFound()->assertSee('<Code>NoSuchBucket</Code>', false)->assertHeader('x-amz-request-id');
        $this->get('/s3/media/absent')->assertNotFound()->assertSee('<Code>NoSuchKey</Code>', false);
        $this->delete('/s3/media/absent?uploadId=missing')->assertNotFound()->assertSee('<Code>NoSuchUpload</Code>', false);
    }

    public function test_multipart_initiation_returns_parseable_xml_for_special_keys(): void
    {
        Bucket::create(['name' => 'media']);

        $response = $this->withoutMiddleware(VerifyS3Signature::class)->post('/s3/media/report%26notes%3Cdraft%3E?uploads')->assertOk();

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertSame('report&notes<draft>', (string) $xml->Key);
    }

    public function test_malformed_copy_source_is_rejected_without_creating_an_object(): void
    {
        Bucket::create(['name' => 'media']);

        $this->withoutMiddleware(VerifyS3Signature::class)->put('/s3/media/copy', [], ['x-amz-copy-source' => 'missing-key-separator'])
            ->assertBadRequest()->assertSee('Invalid copy source.');

        $this->assertDatabaseCount('objects', 0);
    }
}
