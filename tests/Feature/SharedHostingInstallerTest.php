<?php

namespace Tests\Feature;

use App\Services\InstallationEnvironment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Process\Process as NativeProcess;
use Tests\TestCase;

class SharedHostingInstallerTest extends TestCase
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

    public function test_setup_completes_and_passes_admin_password_only_in_process_environment(): void
    {
        $environment = $this->fixture();
        Process::fake();
        $this->questions($environment)->expectsOutputToContain('installation complete')->assertExitCode(0);
        $this->assertFileExists($environment->root.'/.env');
        $this->assertFileDoesNotExist($environment->pendingPath());
        $this->assertSame('', $environment->settings()['ADMIN_PASSWORD']);
        Process::assertRanInOrder([
            fn ($process): bool => in_array('config:clear', $process->command, true),
            fn ($process): bool => in_array('package:discover', $process->command, true),
            fn ($process): bool => in_array('down', $process->command, true),
            fn ($process): bool => in_array('migrate', $process->command, true),
            fn ($process): bool => in_array('db:seed', $process->command, true) && $process->environment['ADMIN_PASSWORD'] === 'short' && ! in_array('short', $process->command, true),
            fn ($process): bool => in_array('object-store:check', $process->command, true),
            fn ($process): bool => in_array('optimize', $process->command, true),
            fn ($process): bool => in_array('up', $process->command, true),
        ]);
    }

    public function test_cancellation_does_not_create_environment_or_database(): void
    {
        $environment = $this->fixture();
        Process::fake();
        $this->questions($environment, false)->expectsOutputToContain('cancelled')->assertExitCode(0);
        $this->assertFileDoesNotExist($environment->root.'/.env');
        $this->assertFileDoesNotExist($environment->root.'/database.sqlite');
        Process::assertNothingRan();
    }

    public function test_existing_install_and_noninteractive_execution_are_rejected(): void
    {
        $environment = $this->fixture();
        File::put($environment->root.'/.env', "APP_KEY=original\n");
        Process::fake();
        $this->artisan('object-store:install')->expectsOutputToContain('already exists')->assertExitCode(1);
        $this->artisan('object-store:install', ['--no-interaction' => true])
            ->expectsOutputToContain('interactive terminal')->assertExitCode(1);
        $this->assertSame("APP_KEY=original\n", File::get($environment->root.'/.env'));
        Process::assertNothingRan();
    }

    public function test_failure_is_redacted_and_resume_preserves_key_without_reseeding(): void
    {
        $environment = $this->fixture();
        Process::fake(['*optimize*' => Process::result(errorOutput: 'failed with short', exitCode: 1), '*' => Process::result()]);
        $this->questions($environment)->expectsOutputToContain('[redacted]')->doesntExpectOutputToContain('failed with short')->assertExitCode(1);
        $key = $environment->settings()['APP_KEY'];
        $this->assertTrue($environment->state()['admin_seeded']);
        Process::fake();
        $this->artisan('object-store:install', ['--resume' => true])
            ->expectsConfirmation('Continue the interrupted installation?', 'yes')
            ->expectsOutputToContain('installation complete')->assertExitCode(0);
        $this->assertSame($key, $environment->settings()['APP_KEY']);
        $this->assertFileDoesNotExist($environment->pendingPath());
        Process::assertRanTimes(fn ($process): bool => in_array('db:seed', $process->command, true), 1);
    }

    public function test_launcher_refuses_existing_environment_before_composer_runs(): void
    {
        $environment = $this->fixture();
        File::copy(base_path('setup.php'), $environment->root.'/setup.php');
        File::copy(base_path('composer.lock'), $environment->root.'/composer.lock');
        File::put($environment->root.'/.env', "APP_KEY=preserve-me\n");
        $check = new NativeProcess([PHP_BINARY, $environment->root.'/setup.php', '--check']);
        $check->run();
        $this->assertSame(0, $check->getExitCode());
        $this->assertStringContainsString('No application files or database tables were changed', $check->getOutput());
        $this->assertDirectoryDoesNotExist($environment->root.'/storage');
        $process = new NativeProcess([PHP_BINARY, $environment->root.'/setup.php']);
        $process->run();
        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('already exists', $process->getErrorOutput());
        $this->assertSame("APP_KEY=preserve-me\n", File::get($environment->root.'/.env'));
        $this->assertDirectoryDoesNotExist($environment->root.'/vendor');
    }

    public function test_resume_before_admin_creation_requests_password_without_replacing_environment(): void
    {
        $environment = $this->fixture();
        Process::fake(['*migrate*' => Process::result(exitCode: 1), '*' => Process::result()]);
        $this->questions($environment)->expectsOutputToContain('migrate step failed')->assertExitCode(1);
        $original = File::get($environment->root.'/.env');
        $this->assertFalse($environment->state()['admin_seeded']);
        Process::fake();
        $this->artisan('object-store:install', ['--resume' => true])
            ->expectsQuestion('Admin password', 'resumed-password')
            ->expectsQuestion('Confirm admin password', 'resumed-password')
            ->expectsConfirmation('Continue the interrupted installation?', 'yes')
            ->expectsOutputToContain('installation complete')->assertExitCode(0);
        $this->assertSame($original, File::get($environment->root.'/.env'));
        Process::assertRanTimes(fn ($process): bool => in_array('db:seed', $process->command, true) && $process->environment['ADMIN_PASSWORD'] === 'resumed-password', 1);
    }

    public function test_cron_preserves_existing_entries_or_prints_host_panel_instructions(): void
    {
        $environment = $this->fixture();
        $existing = '*/5 * * * * echo existing-job';
        Process::fake(['*crontab*-l*' => Process::result(output: $existing."\n"), '*' => Process::result()]);
        $command = $this->questions($environment, cron: true);
        if (PHP_OS_FAMILY === 'Windows') {
            $command->expectsOutputToContain('Automatic cron installation is unavailable');
        } else {
            $command->expectsOutputToContain('existing entries were preserved');
        }
        $command->assertExitCode(0);
        if (PHP_OS_FAMILY !== 'Windows') {
            Process::assertRan(fn ($process): bool => $process->command === ['crontab', '-'] && str_contains($process->input, $existing) && str_contains($process->input, $environment->cronCommand()));
        }
    }

    private function questions(InstallationEnvironment $environment, bool $confirm = true, bool $cron = false): PendingCommand
    {
        return $this->artisan('object-store:install')
            ->expectsQuestion('Website URL (HTTPS)', 'https://storage.example.test')
            ->expectsQuestion('Database engine', 'sqlite')
            ->expectsQuestion('SQLite database file (absolute path)', $environment->root.'/database.sqlite')
            ->expectsQuestion('Private object directory (absolute path)', $environment->root.'/objects')
            ->expectsQuestion('Maximum upload size in MiB', '100')
            ->expectsQuestion('Trash retention in days', '30')
            ->expectsQuestion('Unfinished upload expiry in hours', '24')
            ->expectsQuestion('Admin name', 'Installer Owner')
            ->expectsQuestion('Admin email', 'owner@example.test')
            ->expectsQuestion('Admin password', 'short')
            ->expectsQuestion('Confirm admin password', 'short')
            ->expectsConfirmation('Allow public pages to appear in search engines and AI search?', 'yes')
            ->expectsConfirmation('Install the scheduler in this account’s crontab when supported?', $cron ? 'yes' : 'no')
            ->expectsConfirmation('Install Laravel-S3 with these settings?', $confirm ? 'yes' : 'no');
    }

    private function fixture(): InstallationEnvironment
    {
        $root = storage_path('framework/testing-installer-'.Str::uuid());
        $this->roots[] = $root;
        File::ensureDirectoryExists($root.'/public');
        File::copy(base_path('.env.shared-hosting.example'), $root.'/.env.shared-hosting.example');
        $environment = new InstallationEnvironment($root);
        $this->app->instance(InstallationEnvironment::class, $environment);

        return $environment;
    }
}
