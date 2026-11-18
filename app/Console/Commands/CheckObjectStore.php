<?php

namespace App\Console\Commands;

use App\Services\StorageMutex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class CheckObjectStore extends Command
{
    protected $signature = 'object-store:check';

    protected $description = 'Check storage configuration and database readiness without changing data';

    public function handle(): int
    {
        $root = realpath(config('object-store.root')) ?: config('object-store.root');
        $checks = [
            'Application key configured' => filled(config('app.key')),
            'Upload size limit is positive' => config('object-store.max_bytes') > 0,
            'Trash retention is nonnegative' => config('object-store.trash_days') >= 0,
            'Multipart retention is positive' => config('object-store.multipart_hours') > 0,
            'Storage directory exists and is writable' => is_dir($root) && is_writable($root),
            'No interrupted writes or restore pending' => ! app(StorageMutex::class)->hasPendingWrites(),
            'Storage is outside the public directory' => ! str_starts_with(str_replace('\\', '/', rtrim($root, '/\\')).'/', str_replace('\\', '/', public_path()).'/'),
        ];
        foreach (['buckets', 'objects', 'access_keys', 'audit_events', 'multipart_uploads', 'multipart_parts'] as $table) {
            try {
                $checks['Database table: '.$table] = Schema::hasTable($table);
            } catch (\Throwable) {
                $checks['Database table: '.$table] = false;
            }
        }
        $this->table(['Check', 'Result'], collect($checks)->map(fn (bool $passed, string $name) => [$name, $passed ? 'PASS' : 'FAIL'])->values()->all());
        $this->line('Scheduler: configure cron to run php artisan schedule:run every minute.');

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
