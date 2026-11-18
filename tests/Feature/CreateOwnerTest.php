<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StorageMutex;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;
use Tests\TestCase;

class CreateOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_owner_with_a_confirmed_twelve_character_password(): void
    {
        $this->artisan('object-store:owner', ['email' => ' owner@example.com ', '--name' => ' Storage Owner '])
            ->expectsQuestion('Password', 'TwelveChars!')
            ->expectsQuestion('Confirm password', 'TwelveChars!')
            ->doesntExpectOutputToContain('TwelveChars!')
            ->expectsOutput('Owner created.')
            ->assertSuccessful();

        $owner = User::sole();
        $this->assertSame('owner@example.com', $owner->email);
        $this->assertSame('Storage Owner', $owner->name);
        $this->assertTrue(Hash::check('TwelveChars!', $owner->password));
        $this->assertNotSame('TwelveChars!', $owner->password);
        $this->assertNotEmpty($owner->remember_token);
    }

    public function test_defaults_the_new_owner_name(): void
    {
        $this->artisan('object-store:owner', ['email' => 'owner@example.com'])
            ->expectsQuestion('Password', 'a-long-password')
            ->expectsQuestion('Confirm password', 'a-long-password')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'owner@example.com', 'name' => 'Owner']);
    }

    public function test_both_password_prompts_are_hidden_without_visible_fallback(): void
    {
        $output = Mockery::mock(OutputStyle::class.'[askQuestion]', [new ArrayInput([]), new BufferedOutput]);
        $output->shouldReceive('askQuestion')->twice()
            ->with(Mockery::on(fn (Question $question): bool => $question->isHidden() && ! $question->isHiddenFallback()))
            ->andReturn('hidden-owner-password');
        $exitCode = $this->app->make(Kernel::class)->call('object-store:owner', ['email' => 'owner@example.com'], $output);

        $this->assertSame(0, $exitCode);
        $this->assertTrue(Hash::check('hidden-owner-password', User::sole()->password));
    }

    public function test_owner_mutation_is_serialized_with_backups_but_prompts_do_not_hold_the_mutex(): void
    {
        $insideMutex = false;
        $ownerSaved = false;
        $this->mock(StorageMutex::class)->shouldReceive('run')->once()->andReturnUsing(
            function (callable $callback) use (&$insideMutex): mixed {
                $insideMutex = true;

                try {
                    return $callback();
                } finally {
                    $insideMutex = false;
                }
            }
        );
        User::saving(function () use (&$insideMutex, &$ownerSaved): void {
            $this->assertTrue($insideMutex);
            $ownerSaved = true;
        });
        $output = Mockery::mock(OutputStyle::class.'[askQuestion]', [new ArrayInput([]), new BufferedOutput]);
        $output->shouldReceive('askQuestion')->twice()->andReturnUsing(function () use (&$insideMutex): string {
            $this->assertFalse($insideMutex);

            return 'hidden-owner-password';
        });

        $exitCode = $this->app->make(Kernel::class)->call('object-store:owner', ['email' => 'owner@example.com'], $output);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($ownerSaved);
        $this->assertFalse($insideMutex);
        $this->assertDatabaseHas('users', ['email' => 'owner@example.com']);
    }

    #[DataProvider('invalidIdentities')]
    public function test_rejects_invalid_identity_without_prompting_or_writing(string $email, string $name, string $message): void
    {
        $this->artisan('object-store:owner', ['email' => $email, '--name' => $name])
            ->expectsOutputToContain($message)
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    /** @return array<string, array{string, string, string}> */
    public static function invalidIdentities(): array
    {
        return [
            'missing email' => ['', 'Owner', 'email field is required'],
            'invalid email' => ['not-an-email', 'Owner', 'valid email address'],
            'long email' => [str_repeat('a', 250).'@example.com', 'Owner', 'email'],
            'blank name' => ['owner@example.com', '   ', 'name field is required'],
            'long name' => ['owner@example.com', str_repeat('n', 256), 'name field must not be greater than 255 characters'],
        ];
    }

    #[DataProvider('invalidPasswords')]
    public function test_invalid_password_leaves_existing_credentials_and_sessions_unchanged(?string $password, ?string $confirmation, string $message): void
    {
        config(['session.driver' => 'database']);
        $owner = User::factory()->create(['email' => 'owner@example.com']);
        $original = $owner->fresh()->getAttributes();
        $this->createSession('owner-session', $owner->id);

        $this->artisan('object-store:owner', ['email' => $owner->email, '--reset' => true, '--name' => 'Changed'])
            ->expectsQuestion('Password', $password)
            ->expectsQuestion('Confirm password', $confirmation)
            ->expectsOutputToContain($message)
            ->assertFailed();

        $this->assertSame($original, $owner->fresh()->getAttributes());
        $this->assertDatabaseHas('sessions', ['id' => 'owner-session', 'user_id' => $owner->id]);
    }

    /** @return array<string, array{?string, ?string, string}> */
    public static function invalidPasswords(): array
    {
        return [
            'empty' => ['', '', 'password field is required'],
            'unanswered' => [null, null, 'password field is required'],
            'eleven characters' => ['12345678901', '12345678901', 'at least 12 characters'],
            'mismatch' => ['a-long-password', 'a-different-password', 'confirmation does not match'],
            'missing confirmation' => ['a-long-password', null, 'confirmation does not match'],
            'bcrypt byte limit' => [str_repeat('p', 73), str_repeat('p', 73), 'at most 72 bytes'],
            'bcrypt multibyte limit' => [str_repeat('é', 37), str_repeat('é', 37), 'at most 72 bytes'],
            'bcrypt null byte' => ["long-password\0hidden", "long-password\0hidden", 'contain no null bytes'],
        ];
    }

    public function test_existing_owner_requires_explicit_reset_without_prompting(): void
    {
        $owner = User::factory()->create();
        $original = $owner->fresh()->getAttributes();

        $this->artisan('object-store:owner', ['email' => $owner->email, '--name' => 'Changed'])
            ->expectsOutput('Owner already exists. Use --reset to change the password.')
            ->assertFailed();

        $this->assertSame($original, $owner->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_reset_of_a_missing_owner_fails_without_creating_an_account(): void
    {
        $this->artisan('object-store:owner', ['email' => 'missing@example.com', '--reset' => true])
            ->expectsOutput('Owner does not exist. Omit --reset to create it.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_owner_created_during_password_entry_is_not_overwritten(): void
    {
        $output = Mockery::mock(OutputStyle::class.'[askQuestion]', [new ArrayInput([]), new BufferedOutput]);
        $output->shouldReceive('askQuestion')->with(Mockery::on(fn (Question $question): bool => $question->getQuestion() === 'Password'))
            ->once()->andReturnUsing(function (): string {
                User::factory()->create(['email' => 'owner@example.com', 'name' => 'Concurrent Owner']);

                return 'new-owner-password';
            });
        $output->shouldReceive('askQuestion')->with(Mockery::on(fn (Question $question): bool => $question->getQuestion() === 'Confirm password'))
            ->once()->andReturn('new-owner-password');

        $exitCode = $this->app->make(Kernel::class)->call('object-store:owner', ['email' => 'owner@example.com'], $output);

        $this->assertSame(1, $exitCode);
        $owner = User::sole();
        $this->assertSame('Concurrent Owner', $owner->name);
        $this->assertTrue(Hash::check('password', $owner->password));
        $this->assertFalse(Hash::check('new-owner-password', $owner->password));
    }

    public function test_noninteractive_creation_fails_without_writing(): void
    {
        $this->artisan('object-store:owner', ['email' => 'owner@example.com', '--no-interaction' => true])
            ->expectsOutput('An interactive terminal is required for hidden password entry.')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_noninteractive_reset_preserves_existing_credentials(): void
    {
        $owner = User::factory()->create();
        $original = $owner->fresh()->getAttributes();

        $this->artisan('object-store:owner', ['email' => $owner->email, '--reset' => true, '--no-interaction' => true])
            ->expectsOutput('An interactive terminal is required for hidden password entry.')
            ->assertFailed();

        $this->assertSame($original, $owner->fresh()->getAttributes());
    }

    public function test_reset_rotates_remember_token_and_revokes_only_the_owners_database_sessions(): void
    {
        config(['session.driver' => 'database']);
        $owner = User::factory()->create(['name' => 'Existing Owner']);
        $other = User::factory()->create();
        $otherOriginal = $other->fresh()->getAttributes();
        $oldHash = $owner->password;
        $oldToken = $owner->remember_token;
        $this->createSession('owner-one', $owner->id);
        $this->createSession('owner-two', $owner->id);
        $this->createSession('other-session', $other->id);
        $this->createSession('guest-session', null);

        $this->artisan('object-store:owner', ['email' => $owner->email, '--reset' => true])
            ->expectsQuestion('Password', 'new-owner-password')
            ->expectsQuestion('Confirm password', 'new-owner-password')
            ->expectsOutput('Owner password reset; remember token rotated.')
            ->assertSuccessful();

        $owner->refresh();
        $this->assertSame('Existing Owner', $owner->name);
        $this->assertNotSame($oldHash, $owner->password);
        $this->assertTrue(Hash::check('new-owner-password', $owner->password));
        $this->assertFalse(Hash::check('password', $owner->password));
        $this->assertNotEmpty($owner->remember_token);
        $this->assertNotSame($oldToken, $owner->remember_token);
        $this->assertSame($otherOriginal, $other->fresh()->getAttributes());
        $this->assertDatabaseMissing('sessions', ['user_id' => $owner->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'guest-session']);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_reset_uses_configured_session_connection_and_table(): void
    {
        config([
            'database.connections.recovery_sessions' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:']),
            'session.driver' => 'database',
            'session.connection' => 'recovery_sessions',
            'session.table' => 'owner_sessions',
        ]);
        Schema::connection('recovery_sessions')->create('owner_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable();
        });
        $owner = User::factory()->create();
        DB::connection('recovery_sessions')->table('owner_sessions')->insert([
            ['id' => 'owner-session', 'user_id' => $owner->id],
            ['id' => 'guest-session', 'user_id' => null],
        ]);
        $this->createSession('unrelated-default-session', $owner->id);

        try {
            $this->artisan('object-store:owner', ['email' => $owner->email, '--reset' => true, '--name' => 'Renamed Owner'])
                ->expectsQuestion('Password', 'new-owner-password')
                ->expectsQuestion('Confirm password', 'new-owner-password')
                ->assertSuccessful();

            $this->assertDatabaseMissing('owner_sessions', ['user_id' => $owner->id], 'recovery_sessions');
            $this->assertDatabaseHas('owner_sessions', ['id' => 'guest-session'], 'recovery_sessions');
            $this->assertDatabaseHas('sessions', ['id' => 'unrelated-default-session']);
            $this->assertSame('Renamed Owner', $owner->fresh()->name);
        } finally {
            DB::purge('recovery_sessions');
        }
    }

    public function test_session_deletion_failure_prevents_password_name_and_token_changes(): void
    {
        config(['session.driver' => 'database', 'session.table' => 'missing_sessions']);
        $owner = User::factory()->create();
        $original = $owner->fresh()->getAttributes();

        $this->artisan('object-store:owner', ['email' => $owner->email, '--reset' => true, '--name' => 'Changed'])
            ->expectsQuestion('Password', 'new-owner-password')
            ->expectsQuestion('Confirm password', 'new-owner-password')
            ->expectsOutput('Owner could not be saved. Check the database and session configuration, then retry.')
            ->assertFailed();

        $this->assertSame($original, $owner->fresh()->getAttributes());
    }

    public function test_other_session_backends_warn_without_deleting_database_sessions(): void
    {
        config(['session.driver' => 'file']);
        $owner = User::factory()->create();
        $oldToken = $owner->remember_token;
        $this->createSession('untouched-session', $owner->id);

        $this->artisan('object-store:owner', ['email' => $owner->email, '--reset' => true])
            ->expectsQuestion('Password', 'new-owner-password')
            ->expectsQuestion('Confirm password', 'new-owner-password')
            ->expectsOutputToContain('Non-database sessions were not revoked.')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('new-owner-password', $owner->fresh()->password));
        $this->assertNotSame($oldToken, $owner->fresh()->remember_token);
        $this->assertDatabaseHas('sessions', ['id' => 'untouched-session']);
    }

    private function createSession(string $id, ?int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'payload' => base64_encode('{}'),
            'last_activity' => 1700000000,
        ]);
    }
}
