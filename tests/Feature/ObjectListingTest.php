<?php

namespace Tests\Feature;

use App\Models\Bucket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObjectListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_filters_by_prefix_and_returns_a_continuation_token(): void
    {
        $bucket = Bucket::create(['name' => 'documents']);
        $bucket->objects()->createMany([
            ['key' => 'reports/alpha.txt', 'path' => 'alpha', 'size' => 1, 'etag' => md5('a')],
            ['key' => 'reports/beta.txt', 'path' => 'beta', 'size' => 1, 'etag' => md5('b')],
            ['key' => 'photos/avatar.jpg', 'path' => 'avatar', 'size' => 1, 'etag' => md5('c')],
        ]);

        $response = $this->withoutMiddleware()->get('/s3/documents?prefix=reports/&max-keys=1');

        $response->assertOk()
            ->assertSee('reports/alpha.txt')
            ->assertDontSee('reports/beta.txt')
            ->assertDontSee('photos/avatar.jpg')
            ->assertSee('<IsTruncated>true</IsTruncated>', false)
            ->assertSee('NextContinuationToken');
    }
}
