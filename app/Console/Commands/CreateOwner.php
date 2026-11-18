<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\StorageMutex;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CreateOwner extends Command
{
    protected $signature = 'object-store:owner {email} {--name= : Owner display name} {--reset : Reset an existing owner password and revoke database sessions}';

    protected $description = 'Create or reset the local storage owner';

    public function handle(StorageMutex $mutex): int
    {
        $email = trim($this->argument('email'));
        $existingOwner = User::where('email', $email)->first();
        $name = $this->option('name') === null ? ($existingOwner?->name ?? 'Owner') : trim($this->option('name'));
        $identity = Validator::make(['email' => $email, 'name' => $name], [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($identity->fails()) {
            $this->error($identity->errors()->first());

            return self::FAILURE;
        }

        if ($existingOwner !== null && ! $this->option('reset')) {
            $this->error('Owner already exists. Use --reset to change the password.');

            return self::FAILURE;
        }

        if ($existingOwner === null && $this->option('reset')) {
            $this->error('Owner does not exist. Omit --reset to create it.');

            return self::FAILURE;
        }

        if (! $this->input->isInteractive()) {
            $this->error('An interactive terminal is required for hidden password entry.');

            return self::FAILURE;
        }

        $password = $this->secret('Password', false);
        $confirmation = $this->secret('Confirm password', false);
        $credentials = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], [
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        if ($credentials->fails()) {
            $this->error($credentials->errors()->first());

            return self::FAILURE;
        }

        if (Hash::getDefaultDriver() === 'bcrypt' && (strlen($password) > 72 || str_contains($password, "\0"))) {
            $this->error('Bcrypt passwords must be at most 72 bytes and contain no null bytes.');

            return self::FAILURE;
        }

        $passwordHash = Hash::make($password);

        try {
            $saved = $mutex->run(fn (): bool => (new User)->getConnection()->transaction(function () use ($email, $name, $passwordHash, $existingOwner): bool {
                $owner = User::where('email', $email)->lockForUpdate()->first();

                if ($owner?->getKey() !== $existingOwner?->getKey()) {
                    return false;
                }

                if ($owner !== null && config('session.driver') === 'database') {
                    DB::connection(config('session.connection'))
                        ->table(config('session.table', 'sessions'))
                        ->where('user_id', $owner->getKey())
                        ->delete();
                }

                $owner ??= new User;
                $owner->forceFill([
                    'email' => $email,
                    'name' => $name,
                    'password' => $passwordHash,
                    'remember_token' => Str::random(60),
                ])->save();

                return true;
            }));
        } catch (QueryException $exception) {
            report($exception);
            $this->error('Owner could not be saved. Check the database and session configuration, then retry.');

            return self::FAILURE;
        }

        if (! $saved) {
            $this->error('Owner changed during password entry. Run the command again.');

            return self::FAILURE;
        }

        $this->info($existingOwner === null ? 'Owner created.' : 'Owner password reset; remember token rotated.');

        if ($existingOwner !== null && config('session.driver') !== 'database') {
            $this->warn('Non-database sessions were not revoked. Invalidate existing sessions in your session backend before reopening access.');
        }

        return self::SUCCESS;
    }
}
