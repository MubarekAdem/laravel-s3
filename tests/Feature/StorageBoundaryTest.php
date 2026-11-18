<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyS3Signature;
use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorageBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_returns_configured_region(): void
    {
        Bucket::create(['name' => 'media']);
        config(['object-store.region' => 'eu-west-1']);

        $this->withoutMiddleware(VerifyS3Signature::class)->get('/s3/media?location')->assertOk()->assertSee('<LocationConstraint', false)->assertSee('eu-west-1');
    }

    public function test_restore_preserves_trash_when_quota_would_be_exceeded(): void
    {
        $bucket = Bucket::create(['name' => 'media', 'quota_bytes' => 2]);
        $object = $bucket->objects()->create(['key' => 'large', 'path' => 'large', 'size' => 3, 'etag' => md5('big'), 'deleted_at' => now()]);

        $this->actingAs(User::factory()->create())->post('/buckets/media/objects/'.$object->id.'/restore')->assertConflict();

        $this->assertNotNull($object->fresh()->deleted_at);
    }

    public function test_empty_scopes_cannot_create_an_unrestricted_key(): void
    {
        $this->actingAs(User::factory()->create())->post('/keys', [])->assertSessionHasErrors('scopes');

        $this->assertDatabaseCount('access_keys', 0);
    }

    public function test_wildcard_scope_still_enforces_bucket_restrictions(): void
    {
        AccessKey::create(['access_key' => 'RESTRICTED', 'secret_encrypted' => 'unused', 'scopes' => ['*'], 'bucket_names' => ['allowed']]);

        $this->get('/s3/forbidden', ['Authorization' => 'AWS4-HMAC-SHA256 Credential=RESTRICTED/20261118/us-east-1/s3/aws4_request, SignedHeaders=host, Signature='.str_repeat('0', 64)])
            ->assertForbidden()->assertSee('Access denied for this action.');
    }

    public function test_malformed_presigned_date_returns_denial(): void
    {
        $this->get('/s3/media?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Date=invalid&X-Amz-Expires=60')->assertForbidden()->assertSee('Invalid presigned date.');
    }

    public function test_bucket_with_pending_multipart_upload_cannot_be_deleted(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $bucket->multipartUploads()->create(['key' => 'pending', 'upload_id' => 'pending-upload']);

        $this->withoutMiddleware(VerifyS3Signature::class)->delete('/s3/media')->assertConflict();

        $this->assertModelExists($bucket);
    }
}
