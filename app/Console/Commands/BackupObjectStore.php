<?php

namespace App\Console\Commands;

use App\Services\ObjectStoreBackup;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

class BackupObjectStore extends Command
{
    protected $signature = 'object-store:backup {directory : New private backup directory; its parent must exist}';

    protected $description = 'Back up local files and logical database tables under the global storage lock';

    public function handle(ObjectStoreBackup $backup): int
    {
        try {
            $backup->backup($this->argument('directory'));
            $this->info('Backup complete. Keep this directory private and retain your application key separately.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception::class === RuntimeException::class || $exception instanceof JsonException ? $exception->getMessage() : 'Backup failed. Check database and filesystem readiness.');

            return self::FAILURE;
        }
    }
}
