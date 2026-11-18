<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyS3Signature;
use App\Models\AuditEvent;
use App\Models\Bucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class OperationalHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_s3_writes_use_signatures_while_browser_forms_require_csrf(): void
    {
        $this->app->instance('env', 'production');

        $this->call('PUT', '/s3/media/file', [], [], [], [], 'content')->assertForbidden()->assertSee('AccessDenied');
        $this->post('/login', ['email' => 'owner@example.com', 'password' => 'password'])->assertStatus(419);
    }

    public function test_login_is_throttled_after_five_failed_attempts(): void
    {
        $owner = User::factory()->create(['email' => 'limited@example.com', 'password' => 'correct-password']);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $owner->email, 'password' => 'incorrect'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $owner->email, 'password' => 'correct-password'])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', ['email' => $owner->email, 'password' => 'correct-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($owner);
    }

    public function test_conditional_reads_and_metadata_are_consistent(): void
    {
        $root = storage_path('framework/testing-conditionals');
        File::ensureDirectoryExists($root);
        $path = $root.DIRECTORY_SEPARATOR.'object';
        File::put($path, 'abcdef');
        $bucket = Bucket::create(['name' => 'media']);
        $object = $bucket->objects()->create(['key' => 'file', 'path' => $path, 'size' => 6, 'etag' => md5('abcdef'), 'metadata' => ['x-amz-meta-owner' => 'storage-team']]);
        $this->withoutMiddleware(VerifyS3Signature::class);
        try {
            $this->get('/s3/media/file', ['If-None-Match' => '"'.$object->etag.'"'])->assertStatus(304);
            $this->get('/s3/media/file', ['If-Match' => '"wrong"'])->assertStatus(412)->assertSee('PreconditionFailed');
            $this->get('/s3/media/file', ['Range' => 'bytes=1-2', 'If-Range' => '"old"'])->assertOk()->assertStreamedContent('abcdef');
            $this->call('HEAD', '/s3/media/file')->assertOk()->assertHeader('x-amz-meta-owner', 'storage-team')->assertHeader('Content-Length', '6');
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_audit_search_is_paginated_and_can_find_deleted_buckets(): void
    {
        $owner = User::factory()->create();
        for ($index = 0; $index < 21; $index++) {
            AuditEvent::create(['action' => 'object.delete', 'bucket' => 'removed-bucket', 'object_key' => 'report-'.$index, 'actor' => $owner->email]);
        }
        AuditEvent::create(['action' => 'object.put', 'bucket' => 'other', 'object_key' => 'unrelated']);

        $this->actingAs($owner)->get('/?activity_bucket=removed-bucket&activity_search=report')
            ->assertOk()->assertSee('Page 1 of 2')->assertSee('report-20')->assertDontSee('unrelated');
        $this->get('/?activity_bucket=removed-bucket&activity_search=report&activity_page=2')->assertOk()->assertSee('report-0');
    }
}
