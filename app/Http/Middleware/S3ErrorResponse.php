<?php

namespace App\Http\Middleware;

use App\Models\Bucket;
use App\Models\MultipartUpload;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class S3ErrorResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = (string) Str::uuid();
        $request->attributes->set('s3_request_id', $requestId);
        try {
            $response = $next($request);
        } catch (ModelNotFoundException $exception) {
            $code = match ($exception->getModel()) {
                Bucket::class => 'NoSuchBucket',
                MultipartUpload::class => 'NoSuchUpload',
                default => 'NoSuchKey',
            };

            return $this->errorResponse(404, 'The requested resource does not exist.', $code);
        } catch (HttpExceptionInterface $exception) {
            return $this->errorResponse($exception->getStatusCode(), $exception->getMessage());
        }
        if ($response->getStatusCode() < 400 || str_contains((string) $response->headers->get('Content-Type'), 'xml')) {
            $response->headers->set('x-amz-request-id', $requestId);

            return $response;
        }

        return $this->errorResponse($response->getStatusCode(), trim($response->getContent()));
    }

    private function errorResponse(int $status, string $message, ?string $code = null): Response
    {
        return response()->view('s3.error', [
            'code' => $code ?? match ($status) {
                403 => 'AccessDenied',
                404 => 'NoSuchKey',
                409 => 'BucketNotEmpty',
                412 => 'PreconditionFailed',
                413 => 'EntityTooLarge',
                416 => 'InvalidRange',
                500 => 'InternalError',
                default => 'InvalidRequest',
            },
            'message' => $message ?: 'The request could not be completed.',
        ], $status, ['Content-Type' => 'application/xml', 'x-amz-request-id' => request()->attributes->get('s3_request_id')]);
    }
}
