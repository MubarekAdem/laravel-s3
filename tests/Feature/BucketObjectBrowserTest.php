<?php

namespace Tests\Feature;

use App\Models\Bucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BucketObjectBrowserTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_browse_active_objects_by_prefix(): void
    {
        $owner = User::factory()->create();
        $bucket = Bucket::create(['name' => 'media-assets']);
        $bucket->objects()->create(['key' => 'uploads/2026/logo.svg', 'path' => storage_path('app/object-store/logo'), 'size' => 12, 'etag' => md5('logo'), 'content_type' => 'image/svg+xml']);
        $bucket->objects()->create(['key' => 'archive/old.txt', 'path' => storage_path('app/object-store/old'), 'size' => 3, 'etag' => md5('old'), 'deleted_at' => now()]);

        $this->actingAs($owner)->get('/buckets/media-assets?prefix=uploads/')
            ->assertOk()
            ->assertSee('uploads/2026/logo.svg')
            ->assertDontSee('archive/old.txt');
    }

    public function test_owner_can_move_an_object_to_trash_from_its_bucket(): void
    {
        $owner = User::factory()->create();
        $bucket = Bucket::create(['name' => 'media-assets']);
        $object = $bucket->objects()->create(['key' => 'uploads/logo.svg', 'path' => storage_path('app/object-store/logo'), 'size' => 12, 'etag' => md5('logo')]);

        $this->actingAs($owner)->delete('/buckets/media-assets/objects/'.$object->id)->assertRedirect();

        $this->assertNotNull($object->fresh()->deleted_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'object.delete', 'bucket' => 'media-assets', 'object_key' => 'uploads/logo.svg']);
    }

    public function test_an_object_cannot_be_deleted_through_another_buckets_url(): void
    {
        $owner = User::factory()->create();
        $source = Bucket::create(['name' => 'source-assets']);
        $other = Bucket::create(['name' => 'other-assets']);
        $object = $source->objects()->create(['key' => 'private.txt', 'path' => storage_path('app/object-store/private'), 'size' => 7, 'etag' => md5('private')]);

        $this->actingAs($owner)->delete('/buckets/'.$other->name.'/objects/'.$object->id)->assertNotFound();

        $this->assertNull($object->fresh()->deleted_at);
    }

    public function test_owner_can_download_an_object_from_its_bucket(): void
    {
        $owner = User::factory()->create();
        $bucket = Bucket::create(['name' => 'media-assets']);
        $directory = storage_path('framework/testing-object-browser');
        $path = $directory.DIRECTORY_SEPARATOR.'readme.txt';
        File::ensureDirectoryExists($directory);
        File::put($path, 'Harbor object storage');
        $object = $bucket->objects()->create(['key' => 'docs/readme.txt', 'path' => $path, 'size' => 21, 'etag' => md5('Harbor object storage'), 'content_type' => 'text/plain']);

        try {
            $this->actingAs($owner)->get('/buckets/media-assets/objects/'.$object->id.'/download')
                ->assertOk()
                ->assertDownload('readme.txt');
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
