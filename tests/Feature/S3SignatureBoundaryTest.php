<?php

namespace Tests\Feature;

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Services\StorageMutex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class S3SignatureBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_presigned_location_request_expires_after_its_signed_duration(): void
    {
        $this->freezeTime();
        Bucket::create(['name' => 'media']);
        $secret = 'presigned-local-secret';
        AccessKey::create(['access_key' => 'PRESIGNEDKEY', 'secret_encrypted' => Crypt::encryptString($secret), 'scopes' => ['read'], 'bucket_names' => ['media']]);
        $date = now()->utc()->format('Ymd\\THis\\Z');
        $stamp = substr($date, 0, 8);
        $scope = $stamp.'/us-east-1/s3/aws4_request';
        $query = 'X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential='.rawurlencode('PRESIGNEDKEY/'.$scope).'&X-Amz-Date='.$date.'&X-Amz-Expires=60&X-Amz-SignedHeaders=host&location=';
        $host = parse_url(config('app.url'), PHP_URL_HOST).(parse_url(config('app.url'), PHP_URL_PORT) ? ':'.parse_url(config('app.url'), PHP_URL_PORT) : '');
        $canonical = "GET\n/s3/media\n{$query}\nhost:{$host}\n\nhost\nUNSIGNED-PAYLOAD";
        $toSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n".hash('sha256', $canonical);
        $key = hash_hmac('sha256', $stamp, 'AWS4'.$secret, true);
        foreach (['us-east-1', 's3', 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }
        $url = '/s3/media?'.$query.'&X-Amz-Signature='.hash_hmac('sha256', $toSign, $key);

        $this->get($url)->assertOk()->assertSee('LocationConstraint');
        $this->travel(61)->seconds();
        $this->get($url)->assertForbidden()->assertSee('expired');
    }

    public function test_unsigned_acl_header_cannot_be_added_to_a_signed_request(): void
    {
        Bucket::create(['name' => 'media']);
        $headers = $this->signedHeaders('location=');
        $headers['x-amz-acl'] = 'public-read';

        $this->get('/s3/media?location', $headers)->assertForbidden()->assertSee('not included in the signature');
    }

    public function test_duplicate_query_parameters_and_encoded_values_are_signed_correctly(): void
    {
        $this->freezeTime();
        Bucket::create(['name' => 'media']);
        $headers = $this->signedHeaders('location=&tag=a%20b&tag=a%2Bb');

        $this->get('/s3/media?tag=a%2Bb&location&tag=a%20b', $headers)->assertOk()->assertSee('LocationConstraint');
    }

    public function test_stale_header_signature_is_rejected(): void
    {
        $this->freezeTime();
        Bucket::create(['name' => 'media']);

        $this->get('/s3/media?location', $this->signedHeaders('location=', now()->subMinutes(16)->utc()->format('Ymd\\THis\\Z')))->assertForbidden()->assertSee('outside the allowed window');
    }

    public function test_a_signature_without_the_host_header_is_rejected(): void
    {
        Bucket::create(['name' => 'media']);
        $headers = $this->signedHeaders('location=');
        $headers['Authorization'] = str_replace('SignedHeaders=host;x-amz-date', 'SignedHeaders=x-amz-date', $headers['Authorization']);

        $this->get('/s3/media?location', $headers)->assertForbidden()->assertSee('Invalid signed headers');
    }

    public function test_array_signature_parameters_are_rejected_without_a_server_error(): void
    {
        $this->get('/s3/media?X-Amz-Credential[]=invalid')->assertForbidden()->assertSee('Malformed signature parameter');
    }

    public function test_storage_mutex_excludes_a_second_process_and_releases_after_exception(): void
    {
        $code = '$stream = fopen('.var_export(storage_path('framework/object-store.lock'), true).', "c"); echo flock($stream, LOCK_EX | LOCK_NB) ? "acquired" : "blocked"; fclose($stream);';
        $mutex = app(StorageMutex::class);
        try {
            $mutex->run(function () use ($code): void {
                $process = new Process([PHP_BINARY, '-r', $code]);
                $process->mustRun();
                $this->assertSame('blocked', $process->getOutput());
                throw new \RuntimeException('Test rollback');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Test rollback', $exception->getMessage());
        }
        $process = new Process([PHP_BINARY, '-r', $code]);
        $process->mustRun();
        $this->assertSame('acquired', $process->getOutput());
    }

    private function signedHeaders(string $canonicalQuery, ?string $date = null): array
    {
        $date ??= now()->utc()->format('Ymd\\THis\\Z');
        $stamp = substr($date, 0, 8);
        $scope = $stamp.'/us-east-1/s3/aws4_request';
        $secret = 'local-signature-test-secret';
        AccessKey::create(['access_key' => 'BOUNDARYKEY', 'secret_encrypted' => Crypt::encryptString($secret), 'scopes' => ['read'], 'bucket_names' => ['media']]);
        $host = parse_url(config('app.url'), PHP_URL_HOST).(parse_url(config('app.url'), PHP_URL_PORT) ? ':'.parse_url(config('app.url'), PHP_URL_PORT) : '');
        $payload = hash('sha256', '');
        $canonical = "GET\n/s3/media\n{$canonicalQuery}\nhost:{$host}\nx-amz-date:{$date}\n\nhost;x-amz-date\n{$payload}";
        $toSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n".hash('sha256', $canonical);
        $key = hash_hmac('sha256', $stamp, 'AWS4'.$secret, true);
        foreach (['us-east-1', 's3', 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }
        $signature = hash_hmac('sha256', $toSign, $key);

        return ['Authorization' => "AWS4-HMAC-SHA256 Credential=BOUNDARYKEY/{$scope}, SignedHeaders=host;x-amz-date, Signature={$signature}", 'x-amz-date' => $date];
    }
}
