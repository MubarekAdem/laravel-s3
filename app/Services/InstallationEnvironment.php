<?php

namespace App\Services;

use Dotenv\Dotenv;
use PDO;
use RuntimeException;

class InstallationEnvironment
{
    public readonly string $root;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? base_path();
    }

    public function pendingPath(): string
    {
        return $this->root.'/storage/framework/installation-pending.json';
    }

    public function assertInstallable(bool $resume = false): void
    {
        $exists = file_exists($this->root.'/.env') || is_link($this->root.'/.env');
        if ($exists && ! $resume) {
            throw new RuntimeException('An .env file already exists. Setup will not overwrite it. Use --resume only for an interrupted installation, or follow DEPLOYMENT.md for updates.');
        }
        if ($resume && (! $exists || ! is_file($this->pendingPath()))) {
            throw new RuntimeException('No interrupted installation was found. Existing installations cannot be reinitialized.');
        }
        if ($exists && is_link($this->root.'/.env')) {
            throw new RuntimeException('Setup does not follow an .env symlink.');
        }
    }

    public function privatePath(string $path): string
    {
        if (preg_match('~^(?:[a-zA-Z]:[\\\\/]*|/+)$~', $path)) {
            throw new RuntimeException('A filesystem root cannot be used as a storage path.');
        }
        if (preg_match('/[\x00\r\n]/', $path) || ! preg_match('~^(?:[a-zA-Z]:[\\\\/]|/)~', $path)) {
            throw new RuntimeException('Use an absolute private storage path.');
        }
        $path = str_replace('\\', '/', rtrim($path, '/\\'));
        if (in_array('..', explode('/', $path), true)) {
            throw new RuntimeException('Storage paths cannot contain parent-directory segments.');
        }
        $ancestor = $path;
        $suffix = [];
        while (! file_exists($ancestor)) {
            if (is_link($ancestor) || dirname($ancestor) === $ancestor) {
                throw new RuntimeException('The storage path has an invalid or broken parent.');
            }
            array_unshift($suffix, basename($ancestor));
            $ancestor = dirname($ancestor);
        }
        if ($suffix !== [] && ! is_dir($ancestor)) {
            throw new RuntimeException('A storage path parent is a file, not a directory.');
        }
        $resolved = str_replace('\\', '/', (string) realpath($ancestor)).($suffix ? '/'.implode('/', $suffix) : '');
        $public = str_replace('\\', '/', realpath($this->root.'/public') ?: $this->root.'/public');
        $compare = PHP_OS_FAMILY === 'Windows' ? strtolower($resolved) : $resolved;
        $public = PHP_OS_FAMILY === 'Windows' ? strtolower($public) : $public;
        if ($compare === rtrim($public, '/') || str_starts_with($compare.'/', rtrim($public, '/').'/') || preg_match('~/(?:public_html|htdocs)(?:/|$)~i', $resolved)) {
            throw new RuntimeException('Storage must stay outside all public web directories.');
        }

        return $resolved;
    }

    public function ensureDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Could not create a required private directory.');
        }
        if (! is_writable($path)) {
            throw new RuntimeException('A required private directory is not writable by this account.');
        }
    }

    public function database(array $settings, bool $requireEmpty = true): PDO
    {
        if ($settings['DB_CONNECTION'] === 'sqlite') {
            $path = $this->privatePath($settings['DB_DATABASE']);
            $this->ensureDirectory(dirname($path));
            $pdo = new PDO('sqlite:'.$path);
            chmod($path, 0600);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll();
        } else {
            if (! preg_match('/\A[a-zA-Z0-9.:\-\[\]]+\z/', $settings['DB_HOST']) || ! preg_match('/\A[a-zA-Z0-9_-]+\z/', $settings['DB_DATABASE']) || ! ctype_digit((string) $settings['DB_PORT']) || (int) $settings['DB_PORT'] < 1 || (int) $settings['DB_PORT'] > 65535) {
                throw new RuntimeException('Invalid database host, port, or database name.');
            }
            $pdo = new PDO('mysql:host='.$settings['DB_HOST'].';port='.$settings['DB_PORT'].';dbname='.$settings['DB_DATABASE'].';charset=utf8mb4', $settings['DB_USERNAME'], $settings['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $tables = $pdo->query('SHOW TABLES')->fetchAll();
        }
        if ($requireEmpty && $tables !== []) {
            throw new RuntimeException('The database is not empty. Setup requires a new, empty database and will not modify this one.');
        }

        return $pdo;
    }

    public function create(array $settings): void
    {
        $this->assertInstallable();
        $storage = $this->privatePath($settings['OBJECT_STORE_PATH']);
        if (is_dir($storage) && count(scandir($storage)) > 2) {
            throw new RuntimeException('The object storage directory is not empty. Choose a new directory.');
        }
        $this->ensureDirectory($storage);
        foreach (['storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $directory) {
            $this->ensureDirectory($this->root.'/'.$directory);
        }
        $template = file_get_contents($this->root.'/.env.shared-hosting.example');
        if ($template === false) {
            throw new RuntimeException('The shared hosting environment template is missing.');
        }
        $state = ['admin_seeded' => false, 'admin_email' => $settings['ADMIN_EMAIL'], 'admin_name' => $settings['ADMIN_NAME']];
        $settings = array_merge($settings, ['APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'ADMIN_EMAIL' => '', 'ADMIN_PASSWORD' => '', 'DB_URL' => '']);
        foreach ($settings as $key => $value) {
            if (! preg_match('/\A[A-Z_][A-Z0-9_]*\z/', $key) || preg_match('/[\x00\r\n]/', (string) $value)) {
                throw new RuntimeException('Environment values cannot contain null bytes or line breaks.');
            }
            $encoded = '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], (string) $value).'"';
            $line = $key.'='.$encoded;
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $template = preg_match($pattern, $template) ? preg_replace_callback($pattern, fn (): string => $line, $template) : rtrim($template)."\n".$line."\n";
        }
        Dotenv::parse($template);
        $this->saveState($state);
        $mask = umask(0077);
        try {
            $file = @fopen($this->root.'/.env', 'x');
        } finally {
            umask($mask);
        }
        if ($file === false) {
            throw new RuntimeException('Could not create .env exclusively. No existing environment was overwritten.');
        }
        try {
            if (fwrite($file, $template) !== strlen($template) || ! fflush($file)) {
                throw new RuntimeException('Could not finish writing .env. Restore it before resuming setup.');
            }
        } finally {
            fclose($file);
        }
    }

    public function settings(): array
    {
        return Dotenv::parse(file_get_contents($this->root.'/.env'));
    }

    public function state(): array
    {
        return json_decode(file_get_contents($this->pendingPath()), true, flags: JSON_THROW_ON_ERROR);
    }

    public function saveState(array $state): void
    {
        $this->ensureDirectory(dirname($this->pendingPath()));
        if (is_link($this->pendingPath())) {
            throw new RuntimeException('Installation progress must not be a symlink.');
        }
        $temporary = $this->pendingPath().'.'.bin2hex(random_bytes(8)).'.tmp';
        $mask = umask(0077);
        try {
            $json = json_encode($state, JSON_THROW_ON_ERROR);
            $file = fopen($temporary, 'x');
            if ($file === false) {
                throw new RuntimeException('Could not save installation progress.');
            }
            try {
                if (fwrite($file, $json) !== strlen($json) || ! fflush($file)) {
                    throw new RuntimeException('Could not finish saving installation progress.');
                }
            } finally {
                fclose($file);
            }
            if (! rename($temporary, $this->pendingPath())) {
                throw new RuntimeException('Could not publish installation progress.');
            }
        } finally {
            umask($mask);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function finish(): void
    {
        if (! unlink($this->pendingPath())) {
            throw new RuntimeException('Could not clear the installation progress marker.');
        }
    }

    public function cronCommand(): string
    {
        $command = 'cd '.escapeshellarg($this->root).' && '.escapeshellarg(PHP_BINARY).' artisan schedule:run >> '.escapeshellarg($this->root.'/storage/logs/scheduler.log').' 2>&1';

        return '* * * * * '.str_replace('%', '\\%', $command);
    }
}
