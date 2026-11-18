<?php

namespace Tests\Feature;

use App\Models\Bucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BucketDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_bucket_can_be_deleted(): void
    {
        $bucket = Bucket::create(['name' => 'empty']);

        $response = $this->withoutMiddleware()->delete('/s3/empty');

        $response->assertNoContent();
        $this->assertModelMissing($bucket);
    }

    public function test_bucket_with_objects_returns_conflict_and_is_preserved(): void
    {
        $bucket = Bucket::create(['name' => 'occupied']);
        $bucket->objects()->create(['key' => 'report.txt', 'path' => storage_path('app/object-store/test-file'), 'size' => 1, 'etag' => md5('x')]);

        $response = $this->withoutMiddleware()->delete('/s3/occupied');

        $response->assertStatus(409);
        $this->assertModelExists($bucket);
    }
}
