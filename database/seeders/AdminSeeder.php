<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\StorageMutex;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = config('admin.email');
        $password = config('admin.password');
        if (blank($email) && blank($password)) {
            $this->command?->info('Admin seed skipped. Set ADMIN_EMAIL and ADMIN_PASSWORD in .env.');

            return;
        }
        $data = Validator::make([
            'name' => config('admin.name'),
            'email' => is_string($email) ? trim($email) : $email,
            'password' => $password,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ])->validate();
        if (Hash::getDefaultDriver() === 'bcrypt' && (strlen($data['password']) > 72 || str_contains($data['password'], "\0"))) {
            throw ValidationException::withMessages(['password' => 'Bcrypt passwords must be at most 72 bytes and contain no null bytes.']);
        }
        $mutex = app(StorageMutex::class);
        $created = $mutex->run(function () use ($data, $mutex): bool {
            if ($mutex->hasPendingWrites()) {
                throw new \RuntimeException('Resolve pending storage recovery before seeding an admin.');
            }

            return DB::transaction(function () use ($data): bool {
                if (User::where('email', $data['email'])->exists()) {
                    return false;
                }
                User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => Hash::make($data['password'])]);

                return true;
            });
        });
        $this->command?->info($created ? 'Admin created from environment configuration.' : 'Admin already exists; credentials left unchanged.');
    }
}
