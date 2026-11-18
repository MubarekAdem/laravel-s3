<?php

namespace App\Console\Commands;

use App\Models\MultipartUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PurgeMultipartUploads extends Command
{
    protected $signature = 'object-store:purge-multipart';

    protected $description = 'Permanently remove expired multipart upload parts';

    public function handle(): int
    {
        $uploads = MultipartUpload::where('updated_at', '<', now()->subHours(max(1, config('object-store.multipart_hours'))))->get();
        foreach ($uploads as $upload) {
            File::deleteDirectory(config('object-store.root').DIRECTORY_SEPARATOR.'multipart'.DIRECTORY_SEPARATOR.$upload->upload_id);
            $upload->delete();
        }
        $this->info("Purged {$uploads->count()} multipart upload(s).");

        return self::SUCCESS;
    }
}
