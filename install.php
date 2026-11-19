<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Standalone source installer; no Laravel or Composer dependencies are needed.
 */
final class LaravelS3SourceInstaller
{
    public const REPOSITORY = 'https://github.com/MubarekAdem/laravel-s3.git';

    public function __construct(private readonly ?Closure $process = null) {}

    public function run(array $arguments): int
    {
        $target = null;
        $created = false;
        try {
            $options = $this->options($arguments);
            if (isset($options['help'])) {
                echo "Laravel-S3 source installer\n\nUsage: php install.php [--directory=/private/path/laravel-s3] [--branch=master]\n       php install.php --check\n\nClones the latest branch from ".self::REPOSITORY."\ninto a new directory and launches its interactive setup wizard.\nNo GitHub release, ZIP upload, or GitHub token is required.\nRequires PHP 8.4+, Git, proc_open, and outbound HTTPS.\nOptional: --composer=/absolute/path/composer.phar forwards a custom Composer path.\nExisting directories and installed applications are never overwritten.\n";

                return 0;
            }
            $this->requirements();
            if ($this->execute(['git', '--version']) !== 0) {
                throw new RuntimeException('Git is unavailable. Enable Git in the hosting terminal or ask your host to install it.');
            }
            if (isset($options['check'])) {
                echo "Source installer requirements passed. No code was downloaded or installed.\n";

                return 0;
            }
            $branch = $options['branch'] ?? 'master';
            if (! preg_match('~\A[a-zA-Z0-9][a-zA-Z0-9._/-]*\z~', $branch) || $this->execute(['git', 'check-ref-format', '--branch', $branch]) !== 0) {
                throw new RuntimeException('Use a valid Git branch name.');
            }
            $directory = $options['directory'] ?? $this->directoryQuestion();
            $target = $this->destination($directory);
            if (! mkdir($target, 0755)) {
                throw new RuntimeException('Could not reserve the installation directory. Check permissions or choose another new directory.');
            }
            $created = true;
            echo 'Downloading the latest '.$branch.' source from '.self::REPOSITORY."...\n";
            if ($this->execute(['git', 'clone', '--depth', '1', '--single-branch', '--branch', $branch, '--', self::REPOSITORY, $target]) !== 0) {
                throw new RuntimeException('Git download failed. Check outbound HTTPS, certificates, disk space, and the branch name. Any partial directory is retained for inspection.');
            }
            foreach (['setup.php', 'artisan', 'composer.json', 'composer.lock', '.env.shared-hosting.example'] as $file) {
                if (! is_file($target.'/'.$file) || is_link($target.'/'.$file)) {
                    throw new RuntimeException('The downloaded source is incomplete or contains an invalid setup file. Nothing was executed.');
                }
            }
            if (file_exists($target.'/.env') || is_link($target.'/.env')) {
                throw new RuntimeException('The downloaded source contains an environment file. Setup was stopped to protect existing configuration.');
            }
            echo "Downloaded commit:\n";
            if ($this->execute(['git', '-C', $target, 'rev-parse', '--verify', 'HEAD']) !== 0) {
                throw new RuntimeException('The downloaded Git checkout could not be verified.');
            }
            echo "Source is ready. Installing dependencies and launching the configuration wizard...\n";
            $command = [PHP_BINARY, $target.'/setup.php'];
            if (isset($options['composer'])) {
                $command[] = '--composer='.$options['composer'];
            }
            $status = $this->execute($command, $target);
            if ($status !== 0) {
                echo "Application setup stopped. The downloaded code and any saved configuration were preserved.\n";
                echo 'Open '.$target." in your terminal. Run php setup.php --resume if an interrupted-installation marker exists; otherwise rerun php setup.php after fixing the error.\n";

                return $status;
            }
            echo 'Laravel-S3 is installed at '.$target.".\n";
            echo 'Domain document root: '.$target."/public\n";

            return 0;
        } catch (Throwable $exception) {
            echo 'Source setup stopped: '.$exception->getMessage()."\n";
            if ($created && is_dir($target) && count(scandir($target)) === 2) {
                rmdir($target);
            }

            return 1;
        }
    }

    private function options(array $arguments): array
    {
        $options = [];
        foreach (array_slice($arguments, 1) as $argument) {
            if (in_array($argument, ['--help', '--check'], true)) {
                $options[substr($argument, 2)] = true;
            } elseif (preg_match('/\A--(directory|branch|composer)=(.+)\z/s', $argument, $match) && ! preg_match('/[\x00\r\n]/', $match[2])) {
                $options[$match[1]] = $match[2];
            } else {
                throw new RuntimeException('Unknown or invalid option. Run php install.php --help.');
            }
        }

        return $options;
    }

    private function requirements(): void
    {
        if (PHP_VERSION_ID < 80400 || ! function_exists('proc_open')) {
            throw new RuntimeException('Enable PHP 8.4+ and terminal process execution (proc_open) in your hosting account.');
        }
        $missing = array_filter(['curl', 'ctype', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'PDO', 'Phar', 'session', 'tokenizer', 'xml'], fn (string $extension): bool => ! extension_loaded($extension));
        if ($missing !== [] || (! extension_loaded('pdo_mysql') && ! extension_loaded('pdo_sqlite'))) {
            throw new RuntimeException('Enable the required PHP extensions before downloading code: '.implode(', ', $missing).'. At least one of pdo_mysql or pdo_sqlite is also required.');
        }
    }

    private function directoryQuestion(): string
    {
        $default = getcwd().DIRECTORY_SEPARATOR.'laravel-s3';
        echo 'New installation directory ['.$default.']: ';
        $answer = fgets(STDIN);
        if ($answer === false) {
            throw new RuntimeException('No terminal input was received. Pass --directory=/absolute/private/path.');
        }

        return trim($answer) !== '' ? trim($answer) : $default;
    }

    private function destination(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (preg_match('/[\x00\r\n]/', $path) || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException('Choose a destination without parent-directory segments or control characters.');
        }
        if (! preg_match('~^(?:[a-zA-Z]:/|/)~', $path)) {
            $path = getcwd().'/'.$path;
        }
        $path = rtrim($path, '/');
        $parent = realpath(dirname($path));
        if ($parent === false || ! is_dir($parent) || ! is_writable($parent) || in_array(basename($path), ['.', '..', ''], true)) {
            throw new RuntimeException('The destination must have an existing writable parent directory.');
        }
        $resolved = str_replace('\\', '/', $parent).'/'.basename($path);
        if (preg_match('~/(?:public|public_html|htdocs)(?:/|$)~i', $resolved)) {
            throw new RuntimeException('Install outside public_html/htdocs. The domain should serve only the application public directory.');
        }
        if (file_exists($resolved) || is_link($resolved)) {
            throw new RuntimeException('The destination already exists. Choose a new directory; existing files will not be overwritten.');
        }

        return $resolved;
    }

    private function execute(array $command, ?string $directory = null): int
    {
        if ($this->process !== null) {
            return ($this->process)($command, $directory);
        }
        $environment = array_merge(getenv(), ['GIT_TERMINAL_PROMPT' => '0']);
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $directory, $environment);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start a required terminal process.');
        }

        return proc_close($process);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit((new LaravelS3SourceInstaller)->run($argv));
}
