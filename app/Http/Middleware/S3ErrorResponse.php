<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
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
        try {
            $response = $next($request);
        } catch (ModelNotFoundException) {
            return $this->errorResponse(404, 'The requested resource does not exist.');
        } catch (HttpExceptionInterface $exception) {
            return $this->errorResponse($exception->getStatusCode(), $exception->getMessage());
        }
        if ($response->getStatusCode() < 400 || str_contains((string) $response->headers->get('Content-Type'), 'xml')) {
            return $response;
        }

        return $this->errorResponse($response->getStatusCode(), trim($response->getContent()));
    }

    private function errorResponse(int $status, string $message): Response
    {
        return response()->view('s3.error', [
            'code' => match ($status) {
                403 => 'AccessDenied',
                404 => 'NoSuchKey',
                409 => 'Conflict',
                412 => 'PreconditionFailed',
                413 => 'EntityTooLarge',
                416 => 'InvalidRange',
                default => 'InvalidRequest',
            },
            'message' => $message ?: 'The request could not be completed.',
        ], $status, ['Content-Type' => 'application/xml']);
    }
}
