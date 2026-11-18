<?php

namespace App\Console\Commands;

use App\Models\Bucket;
use App\Models\ObjectRecord;
use App\Services\StorageMutex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class PurgeDeletedObjects extends Command
{
    protected $signature = 'object-store:purge {--dry-run : Count expired objects without deleting files}';

    protected $description = 'Permanently remove expired trashed objects';

    public function handle(): int
    {
        return app(StorageMutex::class)->run(fn () => $this->purge());
    }

    private function purge(): int
    {
        if (app(StorageMutex::class)->hasPendingWrites()) {
            $this->error('Run object-store:recover-writes before cleanup.');

            return self::FAILURE;
        }
        $cutoff = now()->subDays(max(0, config('object-store.trash_days')));
        $query = ObjectRecord::whereNotNull('deleted_at')->where('deleted_at', '<', $cutoff);
        if ($this->option('dry-run')) {
            $this->info('Would purge '.$query->count().' object(s).');

            return self::SUCCESS;
        }

        $purged = 0;
        $failed = 0;
        $query->chunkById(100, function ($objects) use ($cutoff, &$purged, &$failed): void {
            foreach ($objects as $candidate) {
                DB::transaction(function () use ($candidate, $cutoff, &$purged, &$failed): void {
                    Bucket::query()->lockForUpdate()->findOrFail($candidate->bucket_id);
                    $object = ObjectRecord::whereKey($candidate->id)->whereNotNull('deleted_at')->where('deleted_at', '<', $cutoff)->first();
                    if ($object === null) {
                        return;
                    }
                    $expected = config('object-store.root').DIRECTORY_SEPARATOR.$object->bucket_id.DIRECTORY_SEPARATOR.hash('sha256', $object->key);
                    if ($object->path !== $expected || (File::exists($object->path) && ! File::delete($object->path))) {
                        $failed++;
                        $this->error('Could not safely purge object '.$object->id.'.');

                        return;
                    }
                    $object->delete();
                    $purged++;
                });
            }
        });
        $this->info("Purged {$purged} object(s); {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
