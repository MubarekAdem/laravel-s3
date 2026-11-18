<?php

use App\Http\Middleware\CoordinateStorage;
use App\Http\Middleware\S3ErrorResponse;
use App\Http\Middleware\VerifyS3Signature;
use App\Models\Bucket;
use App\Models\MultipartUpload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('web', CoordinateStorage::class);
        $middleware->preventRequestForgery(except: ['s3', 's3/*']);
        $middleware->alias(['s3.errors' => S3ErrorResponse::class, 's3.signature' => VerifyS3Signature::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('s3', 's3/*')) {
                return null;
            }
            $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
            $missing = $exception instanceof ModelNotFoundException ? $exception : $exception->getPrevious();
            $code = match ($status) {
                403 => 'AccessDenied',
                404 => match ($missing instanceof ModelNotFoundException ? $missing->getModel() : null) {
                    Bucket::class => 'NoSuchBucket',
                    MultipartUpload::class => 'NoSuchUpload',
                    default => 'NoSuchKey',
                },
                409 => 'BucketNotEmpty',
                412 => 'PreconditionFailed',
                413 => 'EntityTooLarge',
                416 => 'InvalidRange',
                500 => 'InternalError',
                503 => 'ServiceUnavailable',
                default => 'InvalidRequest',
            };

            return response()->view('s3.error', ['code' => $code, 'message' => $status >= 500 ? 'The request could not be completed.' : ($exception->getMessage() ?: 'The requested resource does not exist.')], $status, ['Content-Type' => 'application/xml']);
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
