<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_and_documentation_are_public(): void
    {
        $this->get('/')->assertOk()->assertSee('Give your files')->assertSee(route('api.docs'))->assertSee(route('login'));
        $this->get('/api/docs')->assertOk()->assertSee('API documentation')->assertSee('Signature V4')->assertSee('Multipart uploads')->assertSee('curl --fail-with-body');
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_docs_display_configured_limits_without_private_configuration(): void
    {
        config(['object-store.region' => 'eu-west-1', 'object-store.max_bytes' => 9437184, 'object-store.root' => '/private/do-not-expose', 'object-store.trash_days' => 14]);

        $this->get('/api/docs')->assertOk()->assertSee('eu-west-1')->assertSee('9,437,184')->assertSee('14 days')->assertDontSee('/private/do-not-expose')->assertDontSee(config('app.key'));
    }

    public function test_authenticated_owner_can_follow_public_pages_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee(route('dashboard'));
        $this->get('/api/docs')->assertOk()->assertSee(route('dashboard'));
        $this->get('/dashboard')->assertOk()->assertSee('Object storage on this server.');
    }

    public function test_documentation_section_links_resolve_to_documented_sections(): void
    {
        $html = $this->get('/api/docs')->assertOk()->getContent();
        preg_match_all('/href="#([a-z-]+)"/', $html, $matches);

        foreach ($matches[1] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html);
        }
    }
}
