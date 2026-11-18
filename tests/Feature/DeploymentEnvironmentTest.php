<?php

namespace Tests\Feature;

use Dotenv\Dotenv;
use Tests\TestCase;

class DeploymentEnvironmentTest extends TestCase
{
    public function test_cpanel_template_has_safe_production_defaults_without_embedded_secrets(): void
    {
        $environment = Dotenv::parse(file_get_contents(base_path('.env.cpanel.example')));

        $this->assertSame('production', $environment['APP_ENV']);
        $this->assertSame('false', $environment['APP_DEBUG']);
        $this->assertSame('', $environment['APP_KEY']);
        $this->assertSame('', $environment['DB_PASSWORD']);
        $this->assertSame('mysql', $environment['DB_CONNECTION']);
        $this->assertStringStartsWith('https://', $environment['APP_URL']);
        $this->assertSame('database', $environment['SESSION_DRIVER']);
        $this->assertSame('true', $environment['SESSION_ENCRYPT']);
        $this->assertSame('true', $environment['SESSION_SECURE_COOKIE']);
        $this->assertSame('true', $environment['SESSION_HTTP_ONLY']);
        $this->assertSame('lax', $environment['SESSION_SAME_SITE']);
        $this->assertSame('null', $environment['SESSION_DOMAIN']);
        $this->assertSame('sync', $environment['QUEUE_CONNECTION']);
        $this->assertSame('local', $environment['FILESYSTEM_DISK']);
        $this->assertSame('/home/CPANELUSER/object-store-data', $environment['OBJECT_STORE_PATH']);
        $this->assertArrayNotHasKey('AWS_ACCESS_KEY_ID', $environment);
        $this->assertArrayNotHasKey('AWS_SECRET_ACCESS_KEY', $environment);
    }

    public function test_environment_templates_expose_valid_local_storage_limits(): void
    {
        foreach (['.env.example', '.env.cpanel.example'] as $template) {
            $environment = Dotenv::parse(file_get_contents(base_path($template)));

            $this->assertNotEmpty($environment['OBJECT_STORE_REGION'], $template);
            $this->assertArrayNotHasKey('AWS_ACCESS_KEY_ID', $environment, $template);
            $this->assertArrayNotHasKey('AWS_SECRET_ACCESS_KEY', $environment, $template);
            $this->assertGreaterThan(0, (int) $environment['OBJECT_STORE_MAX_BYTES'], $template);
            $this->assertGreaterThanOrEqual(0, (int) $environment['OBJECT_STORE_TRASH_DAYS'], $template);
            $this->assertGreaterThan(0, (int) $environment['OBJECT_STORE_MULTIPART_HOURS'], $template);
        }
    }
}
