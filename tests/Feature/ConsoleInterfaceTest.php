<?php

namespace Tests\Feature;

use App\Models\Bucket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsoleInterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_console_sections_are_individually_addressable(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (['overview' => 'Storage overview', 'buckets' => 'All buckets', 'keys' => 'Active credentials', 'activity' => 'Workspace activity'] as $view => $heading) {
            $this->get('/dashboard?view='.$view)->assertOk()->assertSee($heading)->assertViewHas('activeView', $view);
        }
    }

    public function test_delete_control_is_not_offered_for_buckets_containing_trash(): void
    {
        $bucket = Bucket::create(['name' => 'retained-trash']);
        $bucket->objects()->create(['key' => 'old.txt', 'path' => 'old-file', 'size' => 1, 'etag' => md5('x'), 'deleted_at' => now()]);

        $this->actingAs(User::factory()->create())->get('/dashboard?view=buckets')->assertOk()->assertSee('retained-trash')->assertDontSee('data-open-dialog="delete-bucket-'.$bucket->id.'"', false);
    }

    public function test_invalid_console_section_is_rejected(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/dashboard?view=unknown')->assertUnprocessable()->assertJsonValidationErrors('view');
    }

    public function test_activity_filters_select_the_activity_screen(): void
    {
        $this->actingAs(User::factory()->create())->get('/dashboard?activity_search=report')->assertOk()->assertViewHas('activeView', 'activity');
    }
}
