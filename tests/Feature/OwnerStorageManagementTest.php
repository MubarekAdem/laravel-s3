<?php

namespace Tests\Feature;

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerStorageManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_quota_cannot_be_reduced_below_current_usage(): void
    {
        $bucket = Bucket::create(['name' => 'media', 'quota_bytes' => 10485760]);
        $bucket->objects()->create(['key' => 'large', 'path' => 'large', 'size' => 2097152, 'etag' => md5('large')]);

        $this->actingAs(User::factory()->create())->patch('/buckets/media', ['quota_mb' => 1])->assertSessionHasErrors('quota_mb');

        $this->assertSame(10485760, $bucket->fresh()->quota_bytes);
    }

    public function test_purge_requires_explicit_confirmation(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $object = $bucket->objects()->create(['key' => 'old', 'path' => 'old', 'size' => 1, 'etag' => md5('old'), 'deleted_at' => now()]);

        $this->actingAs(User::factory()->create())->delete('/buckets/media/objects/'.$object->id.'/purge')->assertSessionHasErrors('confirm_purge');

        $this->assertModelExists($object);
    }

    public function test_purge_refuses_unexpected_storage_paths_even_with_confirmation(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $object = $bucket->objects()->create(['key' => 'old', 'path' => 'unexpected-path', 'size' => 1, 'etag' => md5('old'), 'deleted_at' => now()]);

        $this->actingAs(User::factory()->create())->delete('/buckets/media/objects/'.$object->id.'/purge', ['confirm_purge' => true])->assertConflict();

        $this->assertModelExists($object);
    }

    public function test_trashed_objects_cannot_be_downloaded_by_owner_route(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $object = $bucket->objects()->create(['key' => 'old', 'path' => 'old', 'size' => 1, 'etag' => md5('old'), 'deleted_at' => now()]);

        $this->actingAs(User::factory()->create())->get('/buckets/media/objects/'.$object->id.'/download')->assertNotFound();
    }

    public function test_owner_can_update_a_quota_and_delete_an_empty_bucket(): void
    {
        $owner = User::factory()->create();
        $bucket = Bucket::create(['name' => 'media']);

        $this->actingAs($owner)->patch('/buckets/media', ['quota_mb' => 256])->assertRedirect();
        $this->assertSame(268435456, $bucket->fresh()->quota_bytes);
        $this->actingAs($owner)->delete('/buckets/media')->assertRedirect('/dashboard');
        $this->assertModelMissing($bucket);
    }

    public function test_owner_can_restore_or_permanently_purge_trashed_objects(): void
    {
        $owner = User::factory()->create();
        $bucket = Bucket::create(['name' => 'media']);
        $object = $bucket->objects()->create(['key' => 'old.txt', 'path' => 'old', 'size' => 1, 'etag' => md5('o'), 'deleted_at' => now()]);

        $this->actingAs($owner)->post('/buckets/media/objects/'.$object->id.'/restore')->assertRedirect();
        $this->assertNull($object->fresh()->deleted_at);
        $object = $object->fresh();
        $object->update(['deleted_at' => now()]);
        $object->update(['path' => config('object-store.root').DIRECTORY_SEPARATOR.$bucket->id.DIRECTORY_SEPARATOR.hash('sha256', $object->key)]);
        $this->assertNotNull($object->fresh()->deleted_at);
        $this->actingAs($owner)->delete('/buckets/media/objects/'.$object->id.'/purge', ['confirm_purge' => true])->assertRedirect();
        $this->assertModelMissing($object);
    }

    public function test_owner_can_update_credential_scopes_and_bucket_access(): void
    {
        $owner = User::factory()->create();
        Bucket::create(['name' => 'media']);
        $key = AccessKey::create(['access_key' => 'HBRMANAGEKEY', 'secret_encrypted' => 'encrypted', 'scopes' => ['read'], 'bucket_names' => []]);

        $this->actingAs($owner)->patch('/keys/'.$key->id, ['scopes' => ['read', 'delete'], 'bucket_names' => ['media']])->assertRedirect();

        $this->assertSame(['read', 'delete'], $key->fresh()->scopes);
        $this->assertSame(['media'], $key->fresh()->bucket_names);
    }
}
