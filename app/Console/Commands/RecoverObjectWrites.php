<?php

namespace App\Console\Commands;

use App\Services\ObjectPublisher;
use App\Services\StorageMutex;
use Illuminate\Console\Command;
use Throwable;

class RecoverObjectWrites extends Command
{
    protected $signature = 'object-store:recover-writes {--dry-run : Validate recovery without changing files}';

    protected $description = 'Reconcile interrupted object publications with committed database records';

    public function handle(ObjectPublisher $publisher, StorageMutex $mutex): int
    {
        return $mutex->run(function () use ($publisher): int {
            $failed = 0;
            try {
                foreach ($publisher->journals() as $path) {
                    try {
                        $result = $publisher->recover($path, (bool) $this->option('dry-run'));
                        $this->info(($this->option('dry-run') ? 'Would reconcile ' : 'Reconciled ').basename($path).': '.$result);
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error(basename($path).': '.$exception->getMessage());
                    }
                }
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        });
    }
}
