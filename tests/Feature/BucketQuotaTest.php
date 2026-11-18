<?php

namespace Tests\Feature;

use App\Models\Bucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BucketQuotaTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_returns_413_when_it_would_exceed_the_bucket_quota(): void
    {
        Bucket::create(['name' => 'limited', 'quota_bytes' => 3]);

        $response = $this->withoutMiddleware()->put('/s3/limited/report.txt', 'four', [
            'Content-Type' => 'text/plain',
            'Content-Length' => '4',
        ]);

        $response->assertStatus(413);
        $this->assertDatabaseCount('objects', 0);
    }
}
