<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LaravelS3SourceInstaller;
use Tests\TestCase;

class SourceInstallerTest extends TestCase
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

    public function test_latest_branch_is_cloned_and_setup_receives_the_composer_option(): void
    {
        $root = $this->fixture();
        $calls = [];
        $installer = new LaravelS3SourceInstaller(function (array $command, ?string $directory) use (&$calls): int {
            $calls[] = [$command, $directory];
            if (in_array('clone', $command, true)) {
                $this->populate(end($command));
            }

            return 0;
        });
        [$status, $output] = $this->runInstaller($installer, ['--directory='.$root.'/application', '--branch=develop', '--composer=/private/composer.phar']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Laravel-S3 is installed', $output);
        $this->assertSame(['git', 'clone', '--depth', '1', '--single-branch', '--branch', 'develop', '--', LaravelS3SourceInstaller::REPOSITORY, str_replace('\\', '/', $root).'/application'], $calls[2][0]);
        $this->assertSame(PHP_BINARY, $calls[4][0][0]);
        $this->assertStringEndsWith('/application/setup.php', $calls[4][0][1]);
        $this->assertSame('--composer=/private/composer.phar', $calls[4][0][2]);
    }

    public function test_existing_directories_and_public_web_roots_are_protected(): void
    {
        $root = $this->fixture();
        File::put($root.'/keep', 'preserve');
        File::ensureDirectoryExists($root.'/public_html');
        $cloned = false;
        $installer = new LaravelS3SourceInstaller(function (array $command) use (&$cloned): int {
            if (in_array('clone', $command, true)) {
                $cloned = true;
            }

            return 0;
        });
        foreach ([$root, $root.'/public_html/application', $root.'/../outside'] as $destination) {
            [$status] = $this->runInstaller($installer, ['--directory='.$destination]);
            $this->assertSame(1, $status);
        }
        $this->assertFalse($cloned);
        $this->assertSame('preserve', File::get($root.'/keep'));
    }

    public function test_failed_clone_does_not_execute_setup_or_delete_partial_files(): void
    {
        $root = $this->fixture();
        $installer = new LaravelS3SourceInstaller(function (array $command): int {
            if (in_array('clone', $command, true)) {
                File::put(end($command).'/partial', 'inspect-me');

                return 1;
            }

            return 0;
        });
        [$status, $output] = $this->runInstaller($installer, ['--directory='.$root.'/application']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('Git download failed', $output);
        $this->assertSame('inspect-me', File::get($root.'/application/partial'));
    }

    public function test_incomplete_checkout_is_not_executed(): void
    {
        $root = $this->fixture();
        $setupCalled = false;
        $installer = new LaravelS3SourceInstaller(function (array $command) use (&$setupCalled): int {
            if ($command[0] === PHP_BINARY) {
                $setupCalled = true;
            }

            return 0;
        });
        [$status, $output] = $this->runInstaller($installer, ['--directory='.$root.'/application']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('downloaded source is incomplete', $output);
        $this->assertFalse($setupCalled);
        $this->assertDirectoryDoesNotExist($root.'/application');
    }

    public function test_interrupted_application_setup_keeps_its_environment(): void
    {
        $root = $this->fixture();
        $installer = new LaravelS3SourceInstaller(function (array $command, ?string $directory): int {
            if (in_array('clone', $command, true)) {
                $this->populate(end($command));
            }
            if ($command[0] === PHP_BINARY) {
                File::put($directory.'/.env', 'APP_KEY=keep-this-key');

                return 1;
            }

            return 0;
        });
        [$status, $output] = $this->runInstaller($installer, ['--directory='.$root.'/application']);
        $this->assertSame(1, $status);
        $this->assertStringContainsString('php setup.php --resume', $output);
        $this->assertSame('APP_KEY=keep-this-key', File::get($root.'/application/.env'));
    }

    public function test_check_mode_is_read_only_and_invalid_options_are_rejected(): void
    {
        $this->fixture();
        $commands = [];
        $installer = new LaravelS3SourceInstaller(function (array $command) use (&$commands): int {
            $commands[] = $command;

            return 0;
        });
        [$status, $output] = $this->runInstaller($installer, ['--check']);
        $this->assertSame(0, $status);
        $this->assertStringContainsString('No code was downloaded', $output);
        $this->assertSame([['git', '--version']], $commands);
        [$status] = $this->runInstaller($installer, ['--directory=bad'.chr(10).'path']);
        $this->assertSame(1, $status);
    }

    private function fixture(): string
    {
        require_once base_path('install.php');
        $root = storage_path('framework/testing-source-installer-'.Str::uuid());
        $this->roots[] = $root;
        File::ensureDirectoryExists($root);

        return $root;
    }

    private function populate(string $target): void
    {
        foreach (['setup.php', 'artisan', 'composer.json', 'composer.lock', '.env.shared-hosting.example'] as $name) {
            File::put($target.'/'.$name, 'source-fixture');
        }
    }

    private function runInstaller(LaravelS3SourceInstaller $installer, array $arguments): array
    {
        ob_start();
        try {
            $status = $installer->run(array_merge(['install.php'], $arguments));

            return [$status, ob_get_contents()];
        } finally {
            ob_end_clean();
        }
    }
}
