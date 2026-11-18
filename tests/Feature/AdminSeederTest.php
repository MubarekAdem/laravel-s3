<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seeding_does_not_create_a_demo_account(): void
    {
        config(['admin.email' => null, 'admin.password' => null]);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_environment_admin_is_hashed_and_can_sign_in(): void
    {
        config(['admin.name' => 'Local Admin', 'admin.email' => 'local-admin@example.com', 'admin.password' => 'Local-password-123!']);

        $this->seed(AdminSeeder::class);

        $admin = User::sole();
        $this->assertSame('Local Admin', $admin->name);
        $this->assertTrue(Hash::check('Local-password-123!', $admin->password));
        $this->assertNotSame('Local-password-123!', $admin->password);
        $this->post('/login', ['email' => 'local-admin@example.com', 'password' => 'Local-password-123!'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($admin);
    }

    public function test_rerunning_seeder_does_not_overwrite_an_existing_admin(): void
    {
        $admin = User::factory()->create(['email' => 'local-admin@example.com', 'name' => 'Existing Admin']);
        $original = $admin->fresh()->getAttributes();
        config(['admin.name' => 'Replacement', 'admin.email' => $admin->email, 'admin.password' => 'Replacement-password!']);

        $this->seed(AdminSeeder::class);
        $this->seed(AdminSeeder::class);

        $this->assertSame($original, $admin->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_weak_environment_password_is_rejected(): void
    {
        config(['admin.name' => 'Owner', 'admin.email' => 'local-admin@example.com', 'admin.password' => 'short']);
        try {
            $this->seed(AdminSeeder::class);
            $this->fail('Weak credentials must not create an admin.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('password', $exception->errors());
            $this->assertDatabaseCount('users', 0);
        }
    }

    public function test_partially_configured_credentials_are_rejected(): void
    {
        config(['admin.email' => 'local-admin@example.com', 'admin.password' => null]);
        $this->expectException(ValidationException::class);

        $this->seed(AdminSeeder::class);
    }
}
