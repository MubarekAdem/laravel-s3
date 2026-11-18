<?php

namespace App\Http\Middleware;

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\ObjectRecord;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

class VerifyS3Signature
{
    public function handle(Request $r, Closure $next): mixed
    {
        if ($this->allowsPublicRead($r)) {
            return $next($r);
        }

        $h = $r->header('Authorization', '');
        if (! preg_match('/Credential=([^\/]+)\/([^,]+),\s*SignedHeaders=([^,]+),\s*Signature=([a-f0-9]{64})/i', $h, $m)) {
            return response('Missing or malformed AWS Signature V4 authorization.', 403);
        }

        $key = AccessKey::where('access_key', $m[1])->whereNull('revoked_at')->first();
        if (! $key) {
            return response('Access denied.', 403);
        }

        if (! $this->allows($key, $r)) {
            return response('Access denied for this action.', 403);
        }

        $parts = explode('/', $m[2]);
        if (count($parts) !== 4 || $parts[2] !== 's3') {
            return response('Invalid credential scope.', 403);
        }

        $date = $r->header('x-amz-date');
        if (! $date) {
            return response('Missing x-amz-date.', 403);
        }

        $headers = '';
        foreach (explode(';', $m[3]) as $n) {
            $headers .= strtolower($n).':'.trim($r->header($n, ''))."\n";
        }

        $q = $r->query();
        ksort($q);
        $payload = $r->header('x-amz-content-sha256', hash('sha256', $r->getContent()));
        $canonical = $r->method()."\n/".ltrim($r->path(), '/')."\n".http_build_query($q, '', '&', PHP_QUERY_RFC3986)."\n".$headers."\n".$m[3]."\n".$payload;
        $string = "AWS4-HMAC-SHA256\n{$date}\n{$m[2]}\n".hash('sha256', $canonical);
        try {
            $secret = Crypt::decryptString($key->secret_encrypted);
        } catch (\Throwable) {
            return response('Invalid credential.', 403);
        }

        $kd = hash_hmac('sha256', $parts[0], 'AWS4'.$secret, true);
        $kr = hash_hmac('sha256', $parts[1], $kd, true);
        $ks = hash_hmac('sha256', 's3', $kr, true);
        $sign = hash_hmac('sha256', 'aws4_request', $ks, true);
        if (! hash_equals(hash_hmac('sha256', $string, $sign), $m[4])) {
            return response('Signature does not match.', 403);
        }

        $key->forceFill(['last_used_at' => now()])->save();
        $r->attributes->set('s3_key', $key);

        return $next($r);
    }

    private function allows(AccessKey $key, Request $request): bool
    {
        $scopes = $key->scopes ?? [];

        if (in_array('*', $scopes, true)) {
            return true;
        }

        if (! in_array($this->actionFor($request), $scopes, true)) {
            return false;
        }

        $bucketNames = $key->bucket_names ?? [];
        $bucketName = $request->segment(2);

        return $bucketName === null || $bucketNames === [] || in_array($bucketName, $bucketNames, true);
    }

    private function actionFor(Request $request): string
    {
        if ($request->isMethod('DELETE')) {
            return 'delete';
        }

        if ($request->isMethod('PUT') || $request->isMethod('POST')) {
            return 'write';
        }

        return count($request->segments()) > 2 ? 'read' : 'list';
    }

    private function allowsPublicRead(Request $request): bool
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return false;
        }

        $segments = $request->segments();

        if (count($segments) < 3 || $segments[0] !== 's3') {
            return false;
        }

        $bucket = Bucket::where('name', $segments[1])->first();

        return $bucket !== null && ObjectRecord::whereBelongsTo($bucket)
            ->where('key', implode('/', array_slice($segments, 2)))
            ->where('is_public', true)
            ->whereNull('deleted_at')
            ->exists();
    }
}
