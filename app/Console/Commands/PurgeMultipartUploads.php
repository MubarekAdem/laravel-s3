<?php

namespace App\Console\Commands;

use App\Models\MultipartUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PurgeMultipartUploads extends Command
{
    protected $signature = 'object-store:purge-multipart {--dry-run : Count expired uploads without deleting files}';

    protected $description = 'Permanently remove expired multipart upload parts';

    public function handle(): int
    {
        $query = MultipartUpload::where('updated_at', '<', now()->subHours(max(1, config('object-store.multipart_hours'))));
        if ($this->option('dry-run')) {
            $this->info('Would purge '.$query->count().' multipart upload(s).');

            return self::SUCCESS;
        }
        $purged = 0;
        $failed = 0;
        $query->chunkById(100, function ($uploads) use (&$purged, &$failed): void {
            foreach ($uploads as $upload) {
                $path = config('object-store.root').DIRECTORY_SEPARATOR.'multipart'.DIRECTORY_SEPARATOR.$upload->upload_id;
                if (! preg_match('/\A[a-zA-Z0-9-]+\z/', $upload->upload_id) || (File::isDirectory($path) && ! File::deleteDirectory($path))) {
                    $failed++;
                    $this->error('Could not safely purge upload '.$upload->id.'.');

                    continue;
                }
                $upload->delete();
                $purged++;
            }
        });
        $this->info("Purged {$purged} multipart upload(s); {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
