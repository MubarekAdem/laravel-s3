<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiDocumentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_index_is_public_and_links_to_reference_endpoints(): void
    {
        $this->get('/llms.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee(route('api.docs'), false)->assertSee(route('ai.full'), false)->assertSee(route('api.openapi'), false)->assertSee('Signature V4');
    }

    public function test_full_text_matches_documentation_without_exposing_private_config(): void
    {
        config(['object-store.region' => 'eu-west-1', 'object-store.max_bytes' => 9437184, 'object-store.root' => '/private/hidden-storage']);
        $this->get('/llms-full.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('eu-west-1')->assertSee('9,437,184')->assertSee('curl --fail-with-body')
            ->assertSee('Multipart uploads')->assertSee('UNSIGNED-PAYLOAD')
            ->assertDontSee('<html', false)->assertDontSee('<script', false)->assertDontSee('/private/hidden-storage')->assertDontSee(config('app.key'));
    }

    public function test_openapi_describes_storage_operations_and_signed_authentication(): void
    {
        config(['object-store.region' => 'eu-west-1', 'object-store.max_bytes' => 9437184]);
        $response = $this->getJson('/api/openapi.json')->assertOk()->assertJsonPath('openapi', '3.1.0')
            ->assertJsonPath('components.securitySchemes.SignatureV4.name', 'Authorization')
            ->assertJsonPath('components.securitySchemes.PresignedSignature.name', 'X-Amz-Signature')
            ->assertJsonPath('x-harbor-limits.region', 'eu-west-1')
            ->assertJsonPath('x-harbor-limits.maxBodyBytes', 9437184);
        $paths = $response->json('paths');
        $this->assertSame(['/', '/{bucket}', '/{bucket}/{key}'], array_keys($paths));
        foreach ($paths as $item) {
            foreach ($item as $method => $operation) {
                if ($method === 'parameters') {
                    continue;
                }
                $this->assertNotEmpty($operation['operationId']);
                $this->assertArrayHasKey('responses', $operation);
            }
        }
        $this->assertStringContainsString('PartNumber', $paths['/{bucket}/{key}']['post']['requestBody']['content']['application/xml']['example']);
    }

    public function test_docs_expose_navigation_and_machine_reference_links(): void
    {
        $this->get('/api/docs')->assertOk()->assertSee('Developer navigation')->assertSee('docs-search')
            ->assertSee(route('ai.index'))->assertSee(route('ai.full'))->assertSee(route('api.openapi'));
    }
}
