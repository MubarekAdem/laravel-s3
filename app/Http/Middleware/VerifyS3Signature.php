<?php

namespace App\Http\Middleware;

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\ObjectRecord;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

class VerifyS3Signature
{
    public function handle(Request $r, Closure $next): mixed
    {
        if ($this->allowsPublicRead($r)) {
            return $next($r);
        }

        foreach (['X-Amz-Algorithm', 'X-Amz-Credential', 'X-Amz-SignedHeaders', 'X-Amz-Signature', 'X-Amz-Date', 'X-Amz-Expires'] as $parameter) {
            if ($r->query($parameter) !== null && ! is_string($r->query($parameter))) {
                return response('Malformed signature parameter.', 403);
            }
        }

        $isPresigned = $r->query('X-Amz-Algorithm') === 'AWS4-HMAC-SHA256';
        if ($isPresigned) {
            [$accessKey, $scope] = explode('/', (string) $r->query('X-Amz-Credential'), 2) + ['', ''];
            $m = [1 => $accessKey, 2 => $scope, 3 => (string) $r->query('X-Amz-SignedHeaders'), 4 => (string) $r->query('X-Amz-Signature')];
            $date = (string) $r->query('X-Amz-Date');
            $expires = (int) $r->query('X-Amz-Expires', 0);

            try {
                $issuedAt = Carbon::createFromFormat('!Ymd\\THis\\Z', $date, 'UTC');
            } catch (\Throwable) {
                return response('Invalid presigned date.', 403);
            }
            if (! $issuedAt || $issuedAt->format('Ymd\\THis\\Z') !== $date || $issuedAt->gt(now()->addMinutes(15)) || ! ctype_digit((string) $r->query('X-Amz-Expires', '')) || $expires < 1 || $expires > 604800 || $issuedAt->addSeconds($expires)->isPast()) {
                return response('Presigned URL has expired.', 403);
            }
        } else {
            $h = $r->header('Authorization', '');
            if (! preg_match('/^AWS4-HMAC-SHA256 Credential=([^\/]+)\/([^,]+),\s*SignedHeaders=([^,]+),\s*Signature=([a-f0-9]{64})$/', $h, $m)) {
                return response('Missing or malformed AWS Signature V4 authorization.', 403);
            }

            $date = $r->header('x-amz-date');
        }

        $key = AccessKey::where('access_key', $m[1])->whereNull('revoked_at')->first();
        if (! $key) {
            return response('Access denied.', 403);
        }

        if (! $this->allows($key, $r)) {
            return response('Access denied for this action.', 403);
        }

        $parts = explode('/', $m[2]);
        if (count($parts) !== 4 || $parts[2] !== 's3' || $parts[3] !== 'aws4_request' || $parts[0] !== substr((string) $date, 0, 8)) {
            return response('Invalid credential scope.', 403);
        }

        if (! $date) {
            return response('Missing x-amz-date.', 403);
        }

        if (! $isPresigned) {
            try {
                $issuedAt = Carbon::createFromFormat('!Ymd\\THis\\Z', $date, 'UTC');
            } catch (\Throwable) {
                return response('Invalid signature date.', 403);
            }
            if (! $issuedAt || $issuedAt->format('Ymd\\THis\\Z') !== $date || abs($issuedAt->getTimestamp() - now()->getTimestamp()) > 900) {
                return response('Request time is outside the allowed window.', 403);
            }
        }

        $signedHeaders = explode(';', $m[3]);
        $sortedHeaders = $signedHeaders;
        sort($sortedHeaders, SORT_STRING);
        if ($sortedHeaders !== $signedHeaders || count(array_unique($signedHeaders)) !== count($signedHeaders) || ! in_array('host', $signedHeaders, true) || ! preg_match('/^[a-f0-9]{64}$/', $m[4])) {
            return response('Invalid signed headers or signature.', 403);
        }
        foreach (array_keys($r->headers->all()) as $name) {
            if (str_starts_with($name, 'x-amz-') && $name !== 'x-amz-content-sha256' && ! in_array($name, $signedHeaders, true)) {
                return response('An AWS header was not included in the signature.', 403);
            }
        }

        $headers = '';
        foreach ($signedHeaders as $n) {
            if (! preg_match('/^[a-z0-9-]+$/', $n) || ! $r->headers->has($n)) {
                return response('A signed header is missing.', 403);
            }
            $headers .= $n.':'.preg_replace('/\s+/', ' ', trim($r->header($n)))."\n";
        }

        $query = $this->canonicalQuery($r);
        $payload = $r->header('x-amz-content-sha256');
        if ($payload === null) {
            if ($isPresigned) {
                $payload = 'UNSIGNED-PAYLOAD';
            } else {
                $stream = $r->getContent(true);
                $context = hash_init('sha256');
                hash_update_stream($context, $stream);
                rewind($stream);
                $payload = hash_final($context);
            }
        }
        if ($payload !== 'UNSIGNED-PAYLOAD' && ! preg_match('/^[a-f0-9]{64}$/', $payload)) {
            return response('Unsupported payload signing mode.', 403);
        }
        $path = explode('?', $r->getRequestUri(), 2)[0];
        $canonicalPath = implode('/', array_map(fn (string $segment) => rawurlencode(rawurldecode($segment)), explode('/', $path)));
        $canonical = $r->method()."\n".$canonicalPath."\n".$query."\n".$headers."\n".$m[3]."\n".$payload;
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

        if (preg_match('/^[a-f0-9]{64}$/', $payload)) {
            $stream = $r->getContent(true);
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);
            rewind($stream);
            if (! hash_equals($payload, hash_final($context))) {
                return response('Payload hash does not match.', 403);
            }
        }

        $key->forceFill(['last_used_at' => now()])->save();
        $r->attributes->set('s3_key', $key);

        return $next($r);
    }

    private function allows(AccessKey $key, Request $request): bool
    {
        $scopes = $key->scopes ?? [];

        if (! in_array('*', $scopes, true) && ! in_array($this->actionFor($request), $scopes, true)) {
            return false;
        }

        $bucketNames = $key->bucket_names ?? [];
        $bucketName = $request->segment(2);

        return $bucketName === null || $bucketNames === [] || in_array($bucketName, $bucketNames, true);
    }

    private function canonicalQuery(Request $request): string
    {
        $query = explode('?', $request->getRequestUri(), 2)[1] ?? '';
        $pairs = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            [$name, $value] = explode('=', $pair, 2) + ['', ''];
            if (rawurldecode($name) !== 'X-Amz-Signature') {
                $pairs[] = [rawurlencode(rawurldecode($name)), rawurlencode(rawurldecode($value))];
            }
        }
        sort($pairs, SORT_REGULAR);

        return implode('&', array_map(fn (array $pair) => $pair[0].'='.$pair[1], $pairs));
    }

    private function actionFor(Request $request): string
    {
        if ($request->isMethod('DELETE') || ($request->isMethod('POST') && $request->has('delete'))) {
            return 'delete';
        }

        if ($request->isMethod('PUT') || $request->isMethod('POST')) {
            return 'write';
        }

        return count($request->segments()) > 2 || $request->has('location') || $request->isMethod('HEAD') ? 'read' : 'list';
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
