<?php

namespace App\Console\Commands;

use App\Services\ObjectStoreBackup;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

class RestoreObjectStore extends Command
{
    protected $signature = 'object-store:restore {directory : Private backup directory} {--verify-only : Verify without restoring} {--verify-restored : Verify a pending restored instance and clear its recovery marker} {--force : Skip confirmation; destination must still be empty}';

    protected $description = 'Verify or restore a local backup into empty tables and an empty object directory';

    public function handle(ObjectStoreBackup $backup): int
    {
        if (! $this->option('verify-only') && ! $this->option('verify-restored') && ! $this->option('force') && ! $this->confirm('Restore owner credentials and storage into an empty installation?')) {
            return self::FAILURE;
        }

        try {
            $backup->restore($this->argument('directory'), (bool) $this->option('verify-only'), (bool) $this->option('verify-restored'));
            $this->info($this->option('verify-only') ? 'Backup verified. No data was restored.' : 'Restore complete.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception::class === RuntimeException::class || $exception instanceof JsonException ? $exception->getMessage() : 'Restore failed. Check database and filesystem readiness.');

            return self::FAILURE;
        }
    }
}
