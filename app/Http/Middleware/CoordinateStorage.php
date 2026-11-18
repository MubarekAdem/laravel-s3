<?php

namespace App\Http\Middleware;

use App\Services\StorageMutex;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CoordinateStorage
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $mutex = app(StorageMutex::class);

        return $mutex->run(function () use ($request, $next, $mutex): Response {
            abort_if($mutex->hasPendingWrites() && ($request->is('s3', 's3/*') || ! $request->isMethodSafe()), 503, 'Storage write recovery is required before accessing objects.');

            return $next($request);
        });
    }
}
