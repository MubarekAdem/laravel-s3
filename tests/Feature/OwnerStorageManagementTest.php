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

    public function test_owner_can_update_a_quota_and_delete_an_empty_bucket(): void
    {
        $owner = User::factory()->create();
        $bucket = Bucket::create(['name' => 'media']);

        $this->actingAs($owner)->patch('/buckets/media', ['quota_mb' => 256])->assertRedirect();
        $this->assertSame(268435456, $bucket->fresh()->quota_bytes);
        $this->actingAs($owner)->delete('/buckets/media')->assertRedirect('/');
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
        $this->assertNotNull($object->fresh()->deleted_at);
        $this->actingAs($owner)->delete('/buckets/media/objects/'.$object->id.'/purge')->assertRedirect();
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
