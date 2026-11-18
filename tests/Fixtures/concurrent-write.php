<?php

use App\Models\Bucket;
use App\Services\ObjectPublisher;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    $bucket = Bucket::findOrFail((int) $argv[3]);
    $object = app(ObjectPublisher::class)->publish($bucket, $argv[1], $argv[2], ['size' => 6, 'etag' => md5('abcdef'), 'content_type' => 'text/plain', 'metadata' => [], 'is_public' => false, 'deleted_at' => null]);
    echo 'published';
} catch (HttpExceptionInterface $exception) {
    echo 'rejected:'.$exception->getStatusCode();
    exit(2);
}
