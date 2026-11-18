<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_have_canonical_metadata_and_valid_structured_data(): void
    {
        config(['app.url' => 'https://storage.example.test', 'seo.indexable' => true]);
        foreach (['/' => '/', '/api/docs?source=search' => '/api/docs'] as $request => $canonical) {
            $html = $this->get($request)->assertOk()
                ->assertSee('rel="canonical" href="https://storage.example.test'.$canonical.'"', false)
                ->assertSee('property="og:image"', false)->assertSee('summary_large_image')
                ->assertSee('index, follow, max-image-preview:large')->getContent();
            preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $match);
            $data = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('https://schema.org', $data['@context']);
            $this->assertSame('Laravel-S3', $data['@graph'][0]['name']);
            $this->assertSame('https://storage.example.test'.$canonical, $data['@graph'][2]['url']);
        }
    }

    public function test_robots_allows_discovery_and_excludes_private_storage_paths(): void
    {
        config(['app.url' => 'https://storage.example.test', 'seo.indexable' => true]);
        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('User-agent: *')->assertSee('Allow: /')->assertSee('Disallow: /s3')
            ->assertSee('Disallow: /dashboard')->assertSee('Sitemap: https://storage.example.test/sitemap.xml');
    }

    public function test_sitemap_contains_only_public_html_pages(): void
    {
        config(['app.url' => 'https://storage.example.test', 'seo.indexable' => true]);
        $xml = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();
        $document = simplexml_load_string($xml);
        $this->assertNotFalse($document);
        $locations = [];
        foreach ($document->url as $url) {
            $locations[] = (string) $url->loc;
        }
        $this->assertSame(['https://storage.example.test/', 'https://storage.example.test/api/docs'], array_values($locations));
    }

    public function test_staging_can_disable_indexing(): void
    {
        config(['seo.indexable' => false]);
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /');
        $this->get('/')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertSee('content="noindex, nofollow"', false);
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('<loc>', false);
    }

    public function test_console_and_login_are_not_indexable(): void
    {
        $this->get('/login')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('/dashboard')->assertRedirect('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->actingAs(User::factory()->create())->get('/profile')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_verification_tokens_are_escaped_and_preview_asset_exists(): void
    {
        config(['seo.google_verification' => 'google-token', 'seo.bing_verification' => 'bing-token']);
        $this->get('/')->assertOk()->assertSee('name="google-site-verification" content="google-token"', false)
            ->assertSee('name="msvalidate.01" content="bing-token"', false);
        config(['seo.google_verification' => '"><script>alert(1)</script>']);
        $this->get('/')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $size = getimagesize(public_path('images/laravel-s3-social.png'));
        $this->assertSame(1200, $size[0]);
        $this->assertSame(630, $size[1]);
    }
}
