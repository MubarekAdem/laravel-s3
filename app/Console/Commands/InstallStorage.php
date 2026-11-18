<?php

namespace App\Console\Commands;

use App\Services\InstallationEnvironment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use PDOException;
use RuntimeException;
use Throwable;

class InstallStorage extends Command
{
    protected $signature = 'object-store:install {--resume : Continue an interrupted installation without replacing its environment or key}';

    protected $description = 'Interactively install Laravel-S3 into a new shared hosting account';

    private array $secrets = [];

    public function handle(InstallationEnvironment $environment): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Setup requires an interactive terminal for hidden password entry.');

            return self::FAILURE;
        }
        $lock = null;
        try {
            $environment->ensureDirectory($environment->root.'/storage/framework');
            $lock = fopen($environment->root.'/storage/framework/installation.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another installation is already running.');
            }
            $resume = (bool) $this->option('resume');
            $environment->assertInstallable($resume);
            $this->info('Laravel-S3 shared hosting setup');
            $password = null;
            if ($resume) {
                $settings = $environment->settings();
                $state = $environment->state();
                $this->line('Resuming the saved installation. The existing application key and environment will be preserved.');
                if (! ($state['admin_seeded'] ?? false)) {
                    $password = $this->ownerPassword();
                }
                if (! $this->confirm('Continue the interrupted installation?', true)) {
                    return self::SUCCESS;
                }
            } else {
                [$settings, $password, $installCron] = $this->questions($environment);
                $this->table(['Setting', 'Value'], [
                    ['Website', $settings['APP_URL']],
                    ['Database', $settings['DB_CONNECTION'].' / '.$settings['DB_DATABASE']],
                    ['Object directory', $settings['OBJECT_STORE_PATH']],
                    ['Owner email', $settings['ADMIN_EMAIL']],
                    ['Search indexing', $settings['SEARCH_INDEXABLE']],
                ]);
                if (! $this->confirm('Install Laravel-S3 with these settings?', true)) {
                    $this->line('Installation cancelled. No environment or database tables were created.');

                    return self::SUCCESS;
                }
                $environment->database($settings);
                $environment->create($settings);
                $state = array_merge($environment->state(), ['install_cron' => $installCron]);
                $environment->saveState($state);
                $settings = $environment->settings();
            }
            $this->secrets[] = $settings['DB_PASSWORD'] ?? '';
            $this->secrets[] = $settings['APP_KEY'] ?? '';
            $childEnvironment = array_diff_key($settings, array_flip(['MAIL_FROM_NAME', 'VITE_APP_NAME']));
            $childEnvironment['APP_CONFIG_CACHE'] = $environment->root.'/bootstrap/cache/config.php';
            $this->artisanProcess($environment, ['config:clear'], $childEnvironment);
            $this->artisanProcess($environment, ['package:discover'], $childEnvironment);
            $this->artisanProcess($environment, ['down', '--retry=60'], $childEnvironment);
            $this->artisanProcess($environment, ['migrate', '--force'], $childEnvironment);
            if (! ($state['admin_seeded'] ?? false)) {
                $this->artisanProcess($environment, ['db:seed', '--class=AdminSeeder', '--force'], array_merge($childEnvironment, [
                    'ADMIN_EMAIL' => $state['admin_email'], 'ADMIN_NAME' => $state['admin_name'], 'ADMIN_PASSWORD' => $password,
                ]));
                $state['admin_seeded'] = true;
                $environment->saveState($state);
            }
            $this->artisanProcess($environment, ['object-store:check'], $childEnvironment);
            $this->artisanProcess($environment, ['optimize'], $childEnvironment);
            if ($state['install_cron'] ?? false) {
                $this->installCron($environment);
            } else {
                $this->warn('Scheduler not installed. Add this cron entry in your hosting panel:');
                $this->line($environment->cronCommand());
            }
            $this->artisanProcess($environment, ['up'], $childEnvironment);
            $environment->finish();
            $this->info('Laravel-S3 installation complete.');
            $this->line('Sign in at '.$settings['APP_URL'].'/login using '.$state['admin_email'].'.');
            $this->line('Domain document root: '.$environment->root.'/public');
            $this->line('Keep .env and the object directory private. Back up .env securely; its application key must be preserved.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof PDOException
                ? 'Database connection failed. Check the database name, credentials, host, port, and enabled PDO driver. No migrations were run.'
                : $this->redact($exception->getMessage()));
            if (is_file($environment->pendingPath()) && is_file($environment->root.'/.env')) {
                $this->warn('Setup stopped. Fix the reported issue, then run php setup.php --resume. Do not delete .env or regenerate its application key.');
            }

            return self::FAILURE;
        } finally {
            $this->secrets = [];
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private function questions(InstallationEnvironment $environment): array
    {
        $url = $this->validatedQuestion('Website URL (HTTPS)', ['required', 'url', function (string $attribute, mixed $value, \Closure $fail): void {
            $parts = parse_url($value);
            if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment'])) {
                $fail('Use an HTTPS website URL without credentials, query parameters, or fragments.');
            }
        }]);
        $driver = $this->choice('Database engine', ['mysql', 'sqlite'], 'mysql');
        if (! extension_loaded('pdo_'.$driver)) {
            throw new RuntimeException('Enable the pdo_'.$driver.' PHP extension in your hosting panel before continuing.');
        }
        $settings = ['APP_URL' => rtrim($url, '/'), 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => $driver];
        if ($driver === 'mysql') {
            $this->line('Create an empty database and database user in your hosting panel before continuing.');
            $settings['DB_HOST'] = $this->validatedQuestion('Database host', ['required', 'regex:/\A[a-zA-Z0-9.:\-\[\]]+\z/'], 'localhost');
            $settings['DB_PORT'] = $this->validatedQuestion('Database port', ['required', 'integer', 'between:1,65535'], '3306');
            $settings['DB_DATABASE'] = $this->validatedQuestion('Database name', ['required', 'regex:/\A[a-zA-Z0-9_-]+\z/']);
            $settings['DB_USERNAME'] = $this->validatedQuestion('Database username', ['required', 'string', 'max:255']);
            $settings['DB_PASSWORD'] = (string) $this->secret('Database password', false);
            $this->secrets[] = $settings['DB_PASSWORD'];
        } else {
            $settings['DB_DATABASE'] = $environment->privatePath($this->validatedQuestion('SQLite database file (absolute path)', ['required', 'string'], $environment->root.'/database/database.sqlite'));
            $settings['DB_HOST'] = 'localhost';
            $settings['DB_PORT'] = '3306';
            $settings['DB_USERNAME'] = '';
            $settings['DB_PASSWORD'] = '';
        }
        $settings['OBJECT_STORE_PATH'] = $environment->privatePath($this->validatedQuestion('Private object directory (absolute path)', ['required', 'string'], $environment->root.'/storage/app/object-store'));
        $megabytes = $this->validatedQuestion('Maximum upload size in MiB', ['required', 'integer', 'between:1,1048576'], '100');
        $settings['OBJECT_STORE_MAX_BYTES'] = (string) ((int) $megabytes * 1048576);
        $settings['OBJECT_STORE_TRASH_DAYS'] = $this->validatedQuestion('Trash retention in days', ['required', 'integer', 'min:0', 'max:36500'], '30');
        $settings['OBJECT_STORE_MULTIPART_HOURS'] = $this->validatedQuestion('Unfinished upload expiry in hours', ['required', 'integer', 'between:1,876000'], '24');
        $settings['ADMIN_NAME'] = $this->validatedQuestion('Admin name', ['required', 'string', 'max:255'], 'Storage Owner');
        $settings['ADMIN_EMAIL'] = $this->validatedQuestion('Admin email', ['required', 'email', 'max:255']);
        $password = $this->ownerPassword();
        $settings['SEARCH_INDEXABLE'] = $this->confirm('Allow public pages to appear in search engines and AI search?', true) ? 'true' : 'false';
        $installCron = $this->confirm('Install the scheduler in this account’s crontab when supported?', true);

        return [$settings, $password, $installCron];
    }

    private function validatedQuestion(string $label, array $rules, ?string $default = null): string
    {
        do {
            $value = $this->ask($label, $default);
            $validator = Validator::make(['value' => $value], ['value' => $rules], attributes: ['value' => $label]);
            if ($validator->fails()) {
                $this->warn($validator->errors()->first());
            }
        } while ($validator->fails());

        return (string) $value;
    }

    private function ownerPassword(): string
    {
        do {
            $password = $this->secret('Admin password', false);
            $confirmation = $this->secret('Confirm admin password', false);
            $valid = is_string($password) && $password !== '' && hash_equals($password, (string) $confirmation)
                && ! preg_match('/[\x00\r\n]/', $password) && (Hash::getDefaultDriver() !== 'bcrypt' || strlen($password) <= 72);
            if (! $valid) {
                $this->warn('Passwords must match and be nonempty. Bcrypt allows at most 72 bytes. Null bytes and line breaks are not supported.');
            }
        } while (! $valid);
        $this->secrets[] = $password;

        return $password;
    }

    private function artisanProcess(InstallationEnvironment $environment, array $arguments, array $variables): void
    {
        $this->line('Running artisan '.$arguments[0].'...');
        $result = Process::path($environment->root)->env($variables)->timeout(900)
            ->run(array_merge([PHP_BINARY, $environment->root.'/artisan'], $arguments, ['--no-interaction']));
        if (! $result->successful()) {
            $this->output->write($this->redact($result->output().$result->errorOutput()));
            throw new RuntimeException('The '.$arguments[0].' step failed.');
        }
        if ($result->output() !== '') {
            $this->output->write($this->redact($result->output()));
        }
    }

    private function installCron(InstallationEnvironment $environment): void
    {
        $command = $environment->cronCommand();
        try {
            if (PHP_OS_FAMILY === 'Windows') {
                throw new RuntimeException('Use your hosting panel to register the scheduler.');
            }
            $current = Process::timeout(15)->run(['crontab', '-l']);
            if (! $current->successful() && ! str_contains(strtolower($current->errorOutput()), 'no crontab')) {
                throw new RuntimeException('The host did not allow reading the current crontab.');
            }
            if (! str_contains($current->output(), $command)) {
                $result = Process::timeout(15)->input(rtrim($current->output())."\n# Laravel-S3 scheduler\n".$command."\n")->run(['crontab', '-']);
                if (! $result->successful()) {
                    throw new RuntimeException('The host did not allow updating the crontab.');
                }
            }
            $this->info('Scheduler cron installed; existing entries were preserved.');
        } catch (Throwable) {
            $this->warn('Automatic cron installation is unavailable. Add this entry in your hosting panel:');
            $this->line($command);
        }
    }

    private function redact(string $text): string
    {
        foreach (array_filter($this->secrets, fn ($secret): bool => is_string($secret) && $secret !== '') as $secret) {
            $text = str_replace($secret, '[redacted]', $text);
        }

        return $text;
    }
}
