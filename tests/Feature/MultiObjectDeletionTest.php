<?php

namespace Tests\Feature;

use App\Models\AccessKey;
use App\Models\Bucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class MultiObjectDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_delete_moves_existing_objects_to_trash_and_reports_each_requested_key(): void
    {
        $bucket = Bucket::create(['name' => 'documents']);
        $bucket->objects()->createMany([
            ['key' => 'reports/alpha.txt', 'path' => 'alpha', 'size' => 1, 'etag' => md5('a')],
            ['key' => 'reports/beta.txt', 'path' => 'beta', 'size' => 1, 'etag' => md5('b')],
        ]);

        $response = $this->withoutMiddleware()->call('POST', '/s3/documents?delete', [], [], [], [], $this->deleteXml(['reports/alpha.txt', 'missing.txt', 'reports/beta.txt']));

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee('<Deleted><Key>reports/alpha.txt</Key></Deleted>', false)
            ->assertSee('<Deleted><Key>missing.txt</Key></Deleted>', false);
        $this->assertNotNull($bucket->objects()->where('key', 'reports/alpha.txt')->firstOrFail()->deleted_at);
        $this->assertNotNull($bucket->objects()->where('key', 'reports/beta.txt')->firstOrFail()->deleted_at);
        $this->assertDatabaseCount('audit_events', 2);
    }

    public function test_bulk_delete_rejects_malformed_requests_without_changing_objects(): void
    {
        $bucket = Bucket::create(['name' => 'documents']);
        $object = $bucket->objects()->create(['key' => 'report.txt', 'path' => 'report', 'size' => 1, 'etag' => md5('r')]);

        $this->withoutMiddleware()->call('POST', '/s3/documents?delete', [], [], [], [], '<Delete><Object>')
            ->assertBadRequest();

        $this->assertNull($object->fresh()->deleted_at);
    }

    public function test_bulk_delete_requires_delete_scope(): void
    {
        $bucket = Bucket::create(['name' => 'documents']);
        $object = $bucket->objects()->create(['key' => 'report.txt', 'path' => 'report', 'size' => 1, 'etag' => md5('r')]);
        $secret = 'scope-test-secret';
        $key = AccessKey::create([
            'access_key' => 'HBRDELETECHECK',
            'secret_encrypted' => Crypt::encryptString($secret),
            'scopes' => ['write'],
            'bucket_names' => [],
        ]);
        $body = $this->deleteXml(['report.txt']);

        $this->call('POST', '/s3/documents?delete', [], [], [], $this->signedHeaders($key, $secret, $body), $body)
            ->assertForbidden();

        $this->assertNull($object->fresh()->deleted_at);
    }

    public function test_bulk_delete_accepts_a_signed_request_with_delete_scope(): void
    {
        $bucket = Bucket::create(['name' => 'documents']);
        $object = $bucket->objects()->create(['key' => 'report.txt', 'path' => 'report', 'size' => 1, 'etag' => md5('r')]);
        $secret = 'delete-scope-secret';
        $key = AccessKey::create([
            'access_key' => 'HBRDELETEALLOWED',
            'secret_encrypted' => Crypt::encryptString($secret),
            'scopes' => ['delete'],
            'bucket_names' => ['documents'],
        ]);
        $body = $this->deleteXml(['report.txt']);

        $this->call('POST', '/s3/documents?delete', [], [], [], $this->signedHeaders($key, $secret, $body), $body)
            ->assertOk()
            ->assertSee('<Deleted><Key>report.txt</Key></Deleted>', false);

        $this->assertNotNull($object->fresh()->deleted_at);
    }

    public function test_signed_payload_cannot_be_replaced_after_signing(): void
    {
        Bucket::create(['name' => 'documents']);
        $secret = 'payload-integrity-secret';
        $key = AccessKey::create([
            'access_key' => 'HBRPAYLOADCHECK',
            'secret_encrypted' => Crypt::encryptString($secret),
            'scopes' => ['delete'],
            'bucket_names' => ['documents'],
        ]);
        $original = $this->deleteXml(['original.txt']);

        $this->call('POST', '/s3/documents?delete', [], [], [], $this->signedHeaders($key, $secret, $original), $this->deleteXml(['replacement.txt']))
            ->assertForbidden()->assertSee('Payload hash does not match.');

        $this->assertDatabaseCount('audit_events', 0);
    }

    private function deleteXml(array $keys): string
    {
        return '<Delete xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'.collect($keys)->map(fn (string $key) => '<Object><Key>'.$key.'</Key></Object>')->implode('').'</Delete>';
    }

    private function signedHeaders(AccessKey $key, string $secret, string $body): array
    {
        $date = '20261118T120000Z';
        $scope = '20261118/us-east-1/s3/aws4_request';
        $payloadHash = hash('sha256', $body);
        $signedHeaders = 'x-amz-content-sha256;x-amz-date';
        $canonical = "POST\n/s3/documents\ndelete=\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$date}\n\n{$signedHeaders}\n{$payloadHash}";
        $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$scope}\n".hash('sha256', $canonical);
        $dateKey = hash_hmac('sha256', '20261118', 'AWS4'.$secret, true);
        $regionKey = hash_hmac('sha256', 'us-east-1', $dateKey, true);
        $serviceKey = hash_hmac('sha256', 's3', $regionKey, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $serviceKey, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return [
            'HTTP_AUTHORIZATION' => "AWS4-HMAC-SHA256 Credential={$key->access_key}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
            'HTTP_X_AMZ_CONTENT_SHA256' => $payloadHash,
            'HTTP_X_AMZ_DATE' => $date,
        ];
    }
}
