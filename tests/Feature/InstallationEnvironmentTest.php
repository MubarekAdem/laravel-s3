<?php

namespace Tests\Feature;

use App\Services\InstallationEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class InstallationEnvironmentTest extends TestCase
{
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $this->assertStringStartsWith(realpath(storage_path('framework')).DIRECTORY_SEPARATOR, realpath($root));
            File::deleteDirectory($root);
        }
        parent::tearDown();
    }

    public function test_credentials_round_trip_without_saving_admin_password(): void
    {
        [$environment, $settings] = $this->fixture();
        $settings['DB_PASSWORD'] = 'literal # "quoted" $value $'.'{UNDEFINED} \\path';
        $environment->create($settings);
        $saved = $environment->settings();
        $this->assertSame($settings['DB_PASSWORD'], $saved['DB_PASSWORD']);
        $this->assertSame('', $saved['ADMIN_PASSWORD']);
        $this->assertSame('', $saved['ADMIN_EMAIL']);
        $this->assertSame('', $saved['DB_URL']);
        $this->assertSame(32, strlen(base64_decode(substr($saved['APP_KEY'], 7), true)));
        $this->assertStringNotContainsString('never-save-admin-password', file_get_contents($environment->root.'/.env'));
    }

    public function test_existing_environment_is_preserved_and_completed_install_cannot_resume(): void
    {
        [$environment, $settings] = $this->fixture();
        $environment->create($settings);
        $original = file_get_contents($environment->root.'/.env');
        try {
            $environment->create($settings);
            $this->fail('An existing environment was overwritten.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }
        $this->assertSame($original, file_get_contents($environment->root.'/.env'));
        $environment->assertInstallable(true);
        $environment->finish();
        $this->expectExceptionMessage('No interrupted installation');
        $environment->assertInstallable(true);
    }

    public function test_public_paths_and_parent_traversal_are_rejected(): void
    {
        [$environment] = $this->fixture();
        foreach ([$environment->root.'/public/objects', $environment->root.'/public_html/objects', $environment->root.'/private/../public/objects'] as $path) {
            try {
                $environment->privatePath($path);
                $this->fail('An unsafe path was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
        $this->assertStringEndsWith('/publicity/objects', $environment->privatePath($environment->root.'/publicity/objects'));
    }

    public function test_nonempty_database_is_not_modified(): void
    {
        [$environment, $settings] = $this->fixture();
        $pdo = $environment->database($settings);
        $pdo->exec('CREATE TABLE existing_data (id INTEGER)');
        $pdo->exec('INSERT INTO existing_data VALUES (42)');
        try {
            $environment->database($settings);
            $this->fail('A populated database was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not empty', $exception->getMessage());
        }
        $this->assertSame(42, (int) $pdo->query('SELECT id FROM existing_data')->fetchColumn());
        $this->assertFileDoesNotExist($environment->root.'/.env');
        $pdo = null;
    }

    public function test_existing_objects_and_environment_injection_are_rejected(): void
    {
        [$environment, $settings] = $this->fixture();
        File::ensureDirectoryExists($settings['OBJECT_STORE_PATH']);
        File::put($settings['OBJECT_STORE_PATH'].'/existing', 'keep');
        try {
            $environment->create($settings);
            $this->fail('An occupied object directory was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('not empty', $exception->getMessage());
        }
        $this->assertSame('keep', File::get($settings['OBJECT_STORE_PATH'].'/existing'));
        $settings['OBJECT_STORE_PATH'] = $environment->root.'/another-private-path';
        $settings['DB_PASSWORD'] = "secret\nAPP_DEBUG=true";
        $this->expectExceptionMessage('cannot contain null bytes or line breaks');
        $environment->create($settings);
    }

    private function fixture(): array
    {
        $root = storage_path('framework/testing-installer-'.Str::uuid());
        $this->roots[] = $root;
        File::ensureDirectoryExists($root.'/public');
        File::copy(base_path('.env.shared-hosting.example'), $root.'/.env.shared-hosting.example');

        return [new InstallationEnvironment($root), [
            'APP_URL' => 'https://storage.example.test', 'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $root.'/database.sqlite', 'DB_PASSWORD' => '',
            'OBJECT_STORE_PATH' => $root.'/private-objects', 'ADMIN_NAME' => 'Owner',
            'ADMIN_EMAIL' => 'owner@example.test', 'ADMIN_PASSWORD' => 'never-save-admin-password',
        ]];
    }
}
