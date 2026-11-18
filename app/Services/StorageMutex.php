<?php

namespace App\Services;

class StorageMutex
{
    private static int $depth = 0;

    public function hasPendingWrites(): bool
    {
        return file_exists(rtrim(config('object-store.root'), '/\\').DIRECTORY_SEPARATOR.'.restore-pending.json') || (glob(rtrim(config('object-store.root'), '/\\').DIRECTORY_SEPARATOR.'.recovery'.DIRECTORY_SEPARATOR.'*.json') ?: []) !== [];
    }

    public function run(callable $callback): mixed
    {
        if (self::$depth > 0) {
            return $callback();
        }
        $handle = fopen(storage_path('framework/object-store.lock'), 'c');
        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new \RuntimeException('Could not acquire storage lock.');
        }
        self::$depth++;
        try {
            return $callback();
        } finally {
            self::$depth--;
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
