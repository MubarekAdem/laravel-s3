<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_requires_authentication(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->patch('/profile')->assertRedirect('/login');
        $this->put('/profile/password')->assertRedirect('/login');
    }

    public function test_owner_can_update_only_their_own_details(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->get('/profile')->assertOk()->assertSee('My profile')->assertSee($owner->email);
        $this->patch('/profile', ['name' => 'Updated Owner', 'email' => $owner->email, 'id' => $other->id, 'password' => 'untrusted'])
            ->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();
        $this->assertSame('Updated Owner', $owner->fresh()->name);
        $this->assertSame($other->name, $other->fresh()->name);
        $this->assertTrue(Hash::check('password', $owner->fresh()->password));
    }

    public function test_email_change_requires_current_password_and_unique_address(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->patch('/profile', ['name' => $owner->name, 'email' => 'updated@example.test'])
            ->assertSessionHasErrors('current_password');
        $this->patch('/profile', ['name' => $owner->name, 'email' => 'updated@example.test', 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->patch('/profile', ['name' => $owner->name, 'email' => $other->email, 'current_password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertSame($owner->email, $owner->fresh()->email);
        $this->patch('/profile', ['name' => $owner->name, 'email' => 'updated@example.test', 'current_password' => 'password'])
            ->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();
        $this->assertSame('updated@example.test', $owner->fresh()->email);
        $this->assertNull($owner->fresh()->email_verified_at);
    }

    public function test_invalid_details_are_not_saved(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->patch('/profile', ['name' => '', 'email' => 'invalid'])->assertSessionHasErrors(['name', 'email']);
        $this->assertSame($owner->name, $owner->fresh()->name);
    }

    public function test_password_change_requires_current_password_and_confirmation(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->put('/profile/password', ['current_password' => 'wrong', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('current_password');
        $this->put('/profile/password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'different'])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $owner->fresh()->password));
    }

    public function test_short_password_is_hashed_and_current_session_survives(): void
    {
        $owner = User::factory()->create();
        $token = $owner->remember_token;
        $this->actingAs($owner)->get('/profile')->assertOk();
        $this->put('/profile/password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('short', $owner->fresh()->password));
        $this->assertNotSame($token, $owner->fresh()->remember_token);
        $this->get('/profile')->assertOk();
        $this->post('/logout');
        $this->post('/login', ['email' => $owner->email, 'password' => 'short'])->assertRedirect('/dashboard');
    }

    public function test_stale_password_session_is_signed_out(): void
    {
        $owner = User::factory()->create();
        $staleHash = $owner->password;
        $owner->update(['password' => 'changed']);
        $this->actingAs($owner)->withSession(['password_hash_web' => $staleHash])->get('/profile')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_password_change_removes_other_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        foreach (['other-device' => $owner->id, 'another-owner' => $other->id] as $id => $userId) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'payload' => '', 'last_activity' => time()]);
        }
        $this->actingAs($owner)->put('/profile/password', ['current_password' => 'password', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertRedirect(route('profile.edit'));
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'another-owner']);
    }

    public function test_bcrypt_limit_is_validated_before_hashing(): void
    {
        $owner = User::factory()->create();
        $long = str_repeat('a', 73);
        $this->actingAs($owner)->put('/profile/password', ['current_password' => 'password', 'password' => $long, 'password_confirmation' => $long])
            ->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check('password', $owner->fresh()->password));
    }
}
