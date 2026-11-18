<?php

namespace App\Console\Commands;

use App\Models\AccessKey;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class CreateAccessKey extends Command
{
    protected $signature = 'object-store:key {--scope=* : read, write, delete, or *}';

    protected $description = 'Create a scoped S3 access key and display its secret once';

    public function handle(): int
    {
        $secret = Str::random(40);
        $key = AccessKey::create(['access_key' => 'HBR'.strtoupper(Str::random(17)), 'secret_encrypted' => Crypt::encryptString($secret), 'scopes' => $this->option('scope') ?: ['*']]);
        $this->table(['Access key', 'Secret', 'Scopes'], [[$key->access_key, $secret, implode(', ', $key->scopes)]]);
        $this->warn('Save the secret now; it cannot be displayed again.');

        return self::SUCCESS;
    }
}
