<?php

use App\Models\Bucket;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
User::create(['name' => 'Browser owner', 'email' => 'browser@example.test', 'password' => Hash::make('browser-smoke-password')]);
Bucket::create(['name' => 'media', 'quota_bytes' => 10485760]);
