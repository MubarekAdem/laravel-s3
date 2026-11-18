<?php

namespace Tests\Feature;

use App\Models\AccessKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_open_dashboard(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->get('/dashboard')->assertOk()->assertSee('Object storage on this server.');
    }

    public function test_owner_can_create_and_revoke_a_credential(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/keys', ['scopes' => ['read', 'write']])->assertRedirect('/dashboard');
        $key = AccessKey::firstOrFail();
        $this->assertSame(['read', 'write'], $key->scopes);
        $this->actingAs($owner)->delete('/keys/'.$key->id)->assertRedirect('/dashboard');
        $this->assertNotNull($key->fresh()->revoked_at);
    }

    public function test_owner_can_create_a_bucket_with_a_quota_and_limit_a_key_to_it(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post('/buckets', ['name' => 'media-assets', 'quota_mb' => 512])->assertRedirect('/dashboard');
        $this->assertDatabaseHas('buckets', ['name' => 'media-assets', 'quota_bytes' => 536870912]);

        $this->actingAs($owner)->post('/keys', ['scopes' => ['read'], 'bucket_names' => ['media-assets']])->assertRedirect('/dashboard');

        $this->assertSame(['media-assets'], AccessKey::firstOrFail()->bucket_names);
    }

    public function test_owner_cannot_create_a_bucket_with_an_invalid_name(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->from('/')->post('/buckets', ['name' => 'Invalid Bucket'])->assertRedirect('/')->assertSessionHasErrors('name');

        $this->assertDatabaseMissing('buckets', ['name' => 'Invalid Bucket']);
    }
}
