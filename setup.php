<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Standalone bootstrap: runs before Composer dependencies are available.
 */
function setupRun(array $command, string $root): int
{
    $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root);
    if (! is_resource($process)) {
        throw new RuntimeException('Could not start a required terminal process.');
    }

    return proc_close($process);
}

function setupDownload(string $url): string
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Laravel-S3-Setup']);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if (! is_string($body) || $status !== 200 || strlen($body) > 2097152) {
        throw new RuntimeException('Composer download failed. Check outbound HTTPS access and the PHP CA certificate configuration. '.$error);
    }

    return $body;
}

function setupComposer(string $root, ?string $provided): array
{
    $directories = explode(PATH_SEPARATOR, (string) getenv('PATH'));
    array_unshift($directories, dirname(PHP_BINARY));
    $candidates = $provided !== null ? [$provided] : [];
    if ($provided === null) {
        foreach ($directories as $directory) {
            $candidates[] = rtrim($directory, '/\\').'/composer.phar';
            $candidates[] = rtrim($directory, '/\\').'/composer';
        }
    }
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $path = realpath($candidate);
            $header = file_get_contents($path, false, null, 0, 512);
            if (str_ends_with($path, '.phar') || str_contains($header, '<?php')) {
                return [PHP_BINARY, $path];
            }
            if (PHP_OS_FAMILY !== 'Windows' && is_executable($path)) {
                return [$path];
            }
        }
    }
    if ($provided !== null) {
        throw new RuntimeException('The Composer path is invalid. Supply a composer.phar file or a Composer PHP executable.');
    }
    $directory = $root.'/storage/framework';
    $phar = $directory.'/setup-composer.phar';
    if (is_link($phar)) {
        throw new RuntimeException('The local Composer path must not be a symlink.');
    }
    if (! is_file($phar)) {
        fwrite(STDOUT, "Composer was not found. Downloading its verified installer into private application storage...\n");
        $checksum = trim(setupDownload('https://composer.github.io/installer.sig'));
        $installer = setupDownload('https://getcomposer.org/installer');
        if (! preg_match('/\A[a-f0-9]{96}\z/', $checksum) || ! hash_equals($checksum, hash('sha384', $installer))) {
            throw new RuntimeException('Composer installer checksum verification failed. Nothing was executed.');
        }
        $path = $directory.'/setup-composer-installer.php';
        if (is_link($path)) {
            throw new RuntimeException('The installer download path must not be a symlink.');
        }
        if (file_put_contents($path, $installer, LOCK_EX) === false) {
            throw new RuntimeException('Could not save the verified Composer installer.');
        }
        chmod($path, 0600);
        try {
            if (setupRun([PHP_BINARY, $path, '--quiet', '--install-dir='.$directory, '--filename=setup-composer.phar'], $root) !== 0) {
                throw new RuntimeException('Composer installation failed. Check the network connection and available disk space, then rerun setup.');
            }
        } finally {
            unlink($path);
        }
        chmod($phar, 0600);
    }

    return [PHP_BINARY, $phar];
}

$root = __DIR__;
$lock = null;
try {
    $options = getopt('', ['help', 'check', 'resume', 'composer:']);
    foreach (array_slice($argv, 1) as $argument) {
        if (! in_array($argument, ['--help', '--check', '--resume'], true) && ! str_starts_with($argument, '--composer=')) {
            throw new RuntimeException('Unknown option. Run php setup.php --help.');
        }
    }
    if (isset($options['help'])) {
        fwrite(STDOUT, "Laravel-S3 interactive shared hosting installer\n\nUsage: php setup.php [--resume] [--composer=/absolute/path/composer.phar]\n       php setup.php --check\n\nChecks PHP requirements, installs locked production dependencies, then asks for the website URL,\ndatabase, private storage path, admin credentials, retention, indexing, and scheduler setup.\nRequires PHP 8.4+, required extensions, terminal process execution, and a dedicated empty database.\nPHP/extensions and domain settings must be enabled in your hosting panel.\nExisting .env files are protected; --resume only continues an interrupted installer run.\n");
        exit(0);
    }
    if (PHP_VERSION_ID < 80400) {
        throw new RuntimeException('Enable PHP 8.4 or newer for this terminal in your hosting panel.');
    }
    $missing = array_filter(['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pcre', 'PDO', 'Phar', 'session', 'tokenizer', 'xml'], fn (string $extension): bool => ! extension_loaded($extension));
    if ($missing !== []) {
        throw new RuntimeException('Enable these PHP extensions in your hosting panel: '.implode(', ', $missing));
    }
    if (! function_exists('proc_open')) {
        throw new RuntimeException('The host must allow proc_open for Composer and Artisan terminal commands.');
    }
    if (! extension_loaded('pdo_mysql') && ! extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('Enable pdo_mysql or pdo_sqlite in your hosting PHP configuration.');
    }
    if (! is_file($root.'/composer.lock') || ! is_file($root.'/.env.shared-hosting.example')) {
        throw new RuntimeException('The upload is incomplete: composer.lock and .env.shared-hosting.example are required.');
    }
    if (preg_match('~/(?:public_html|htdocs)(?:/|$)~i', str_replace('\\', '/', $root))) {
        throw new RuntimeException('Extract the application outside public_html/htdocs, then point the domain document root to its public directory.');
    }
    if (isset($options['check'])) {
        fwrite(STDOUT, "PHP and terminal requirements passed.\nDocument root: ".$root."/public\nMySQL PDO: ".(extension_loaded('pdo_mysql') ? 'available' : 'not enabled')."\nSQLite PDO: ".(extension_loaded('pdo_sqlite') ? 'available' : 'not enabled')."\nNo application files or database tables were changed.\n");
        exit(0);
    }
    $pending = $root.'/storage/framework/installation-pending.json';
    $hasEnvironment = file_exists($root.'/.env') || is_link($root.'/.env');
    $resume = isset($options['resume']);
    if ($hasEnvironment && ! $resume) {
        throw new RuntimeException('An .env file already exists. Setup will not overwrite it. For an interrupted installation use php setup.php --resume; for updates follow DEPLOYMENT.md.');
    }
    if ($resume && (! $hasEnvironment || ! is_file($pending))) {
        throw new RuntimeException('No interrupted installation was found. Existing installations cannot be reinitialized.');
    }
    foreach (['storage/framework', 'bootstrap/cache'] as $directory) {
        if (! is_dir($root.'/'.$directory) && ! mkdir($root.'/'.$directory, 0700, true)) {
            throw new RuntimeException('Could not create a required application directory. Check ownership and permissions.');
        }
    }
    $lock = fopen($root.'/storage/framework/setup-bootstrap.lock', 'c');
    if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another setup process is running.');
    }
    foreach (['config.php', 'packages.php', 'services.php', 'events.php', 'routes-v7.php'] as $cache) {
        $path = $root.'/bootstrap/cache/'.$cache;
        if ((is_file($path) || is_link($path)) && ! unlink($path)) {
            throw new RuntimeException('Could not clear copied Laravel cache files. Check bootstrap/cache permissions.');
        }
    }
    $composer = setupComposer($root, $options['composer'] ?? null);
    fwrite(STDOUT, "Installing the locked production dependencies. No Node installation is needed for the current interface.\n");
    if (setupRun(array_merge($composer, ['install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--no-scripts']), $root) !== 0
        || setupRun(array_merge($composer, ['check-platform-reqs', '--no-dev']), $root) !== 0) {
        throw new RuntimeException('Dependency setup failed. Check the reported PHP requirements, network access, and disk space. Fix the issue and rerun the same setup command.');
    }
    $command = [PHP_BINARY, $root.'/artisan', 'object-store:install'];
    if ($resume) {
        $command[] = '--resume';
    }
    $status = setupRun($command, $root);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Setup stopped: '.$exception->getMessage()."\n");
    $status = 1;
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
exit($status);
