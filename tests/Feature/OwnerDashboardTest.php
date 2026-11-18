<?php
namespace Tests\Feature;
use App\Models\AccessKey; use App\Models\User; use Illuminate\Foundation\Testing\RefreshDatabase; use Tests\TestCase;
class OwnerDashboardTest extends TestCase {use RefreshDatabase;public function test_owner_can_open_dashboard():void{$owner=User::factory()->create();$this->actingAs($owner)->get('/')->assertOk()->assertSee('Object storage on this server.');}public function test_owner_can_create_and_revoke_a_credential():void{$owner=User::factory()->create();$this->actingAs($owner)->post('/keys',['scopes'=>['read','write']])->assertRedirect('/');$key=AccessKey::firstOrFail();$this->assertSame(['read','write'],$key->scopes);$this->actingAs($owner)->delete('/keys/'.$key->id)->assertRedirect('/');$this->assertNotNull($key->fresh()->revoked_at);}}
