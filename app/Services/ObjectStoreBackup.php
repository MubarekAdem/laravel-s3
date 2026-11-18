<?php

namespace App\Services;

use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class ObjectStoreBackup
{
    private const TABLES = ['users', 'buckets', 'objects', 'access_keys', 'audit_events', 'multipart_uploads', 'multipart_parts'];

    private const MAX_MANIFEST_BYTES = 16777216;

    private const MAX_ROW_BYTES = 8388608;

    private const MAX_FILES = 20000;

    public function backup(string $directory): void
    {
        app(StorageMutex::class)->run(function () use ($directory): void {
            $root = $this->root();
            $this->require(! is_link($root.'/.recovery') && (! file_exists($root.'/.recovery') || (is_dir($root.'/.recovery') && count(scandir($root.'/.recovery')) === 2)), 'Resolve recovery journals before backup.');
            $target = $this->archivePath($directory, $root, false);
            $this->makeDirectory($target);
            try {
                $manifest = ['version' => 1, 'created_at' => now()->toIso8601String(), 'key_fingerprint' => $this->keyFingerprint(), 'tables' => [], 'files' => []];
                foreach (self::TABLES as $table) {
                    $relative = 'tables/'.$table.'.jsonl';
                    $this->makeDirectory($target.'/tables');
                    $stream = $this->open($target.'/'.$relative, 'xb');
                    $count = 0;
                    try {
                        foreach (DB::table($table)->lazyById(10) as $record) {
                            $row = (array) $record;
                            if ($table === 'users') {
                                $row['remember_token'] = null;
                            }
                            if (in_array($table, ['objects', 'multipart_parts'], true)) {
                                $source = $this->existingPath((string) $row['path']);
                                $this->require($this->inside($source, $root) && is_file($source), 'Stored file is outside the object root or missing.');
                                $row['path'] = substr($source, strlen($root) + 1);
                                $this->relativePath($row['path']);
                                $file = 'data/'.$row['path'];
                                if (! isset($manifest['files'][$file])) {
                                    $this->copyStream($source, $target.'/'.$file);
                                    $manifest['files'][$file] = $this->fileDetails($target.'/'.$file);
                                    $this->require(count($manifest['files']) <= self::MAX_FILES, 'Backup exceeds the file inventory limit.');
                                }
                                $this->require((int) $row['size'] === $manifest['files'][$file]['size'], 'Stored file size does not match the database.');
                            }
                            $json = json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                            $this->require(strlen($json) < self::MAX_ROW_BYTES, 'Database row exceeds the backup row limit.');
                            $this->write($stream, $json);
                            $count++;
                        }
                    } finally {
                        fclose($stream);
                    }
                    $manifest['tables'][$table] = ['columns' => $this->columns($table), 'rows' => $count];
                    $manifest['files'][$relative] = $this->fileDetails($target.'/'.$relative);
                }
                $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                $this->require(strlen($json) <= self::MAX_MANIFEST_BYTES, 'Backup manifest exceeds the size limit.');
                $stream = $this->open($target.'/manifest.json', 'xb');
                try {
                    $this->write($stream, $json);
                } finally {
                    fclose($stream);
                }
            } catch (Throwable $exception) {
                File::deleteDirectory($target);
                throw $exception;
            }
        });
    }

    public function restore(string $directory, bool $verifyOnly = false, bool $verifyRestored = false): void
    {
        app(StorageMutex::class)->run(function () use ($directory, $verifyOnly, $verifyRestored): void {
            $this->requireTransactionalDatabase();
            $root = $this->root();
            $source = $this->archivePath($directory, $root, true);
            $manifest = $this->manifest($source);
            $this->verify($source, $manifest);
            if ($verifyRestored) {
                $this->verifyRestored($source, $root, $manifest);

                return;
            }
            if ($verifyOnly) {
                return;
            }
            $this->requireEmpty($root);
            $stage = dirname($root).'/.object-store-restore-'.bin2hex(random_bytes(16));
            $this->makeDirectory($stage);
            $published = false;
            $committed = false;
            try {
                $this->makeDirectory($stage.'/data');
                foreach ($manifest['files'] as $relative => $details) {
                    $this->copyStream($source.'/'.$relative, $stage.'/'.$relative);
                }
                $this->verify($stage, $manifest);
                $this->require(File::put($stage.'/data/.restore-pending.json', json_encode(['version' => 1, 'manifest_sha256' => hash_file('sha256', $source.'/manifest.json')], JSON_THROW_ON_ERROR)) !== false, 'Unable to persist restore marker.');
                DB::transaction(function () use ($stage, $root, &$published): void {
                    $this->requireEmpty($root);
                    foreach (self::TABLES as $table) {
                        $batch = [];
                        $batchBytes = 0;
                        foreach ($this->rows($stage.'/tables/'.$table.'.jsonl') as $row) {
                            if (in_array($table, ['objects', 'multipart_parts'], true)) {
                                $row['path'] = rtrim((string) config('object-store.root'), '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $row['path']);
                            }
                            $batch[] = $row;
                            $batchBytes += strlen(json_encode($row, JSON_THROW_ON_ERROR));
                            if (count($batch) === 25 || $batchBytes >= 1048576) {
                                DB::table($table)->insert($batch);
                                $batch = [];
                                $batchBytes = 0;
                            }
                        }
                        if ($batch !== []) {
                            DB::table($table)->insert($batch);
                        }
                    }
                    $this->require(rmdir($root), 'Unable to prepare empty destination.');
                    if (! rename($stage.'/data', $root)) {
                        $this->makeDirectory($root);
                        throw new RuntimeException('Unable to publish restored files.');
                    }
                    $published = true;
                });
                $committed = true;
                $this->require(File::delete($root.'/.restore-pending.json'), 'Restore committed but marker remains; run restore --verify-restored.');
            } catch (Throwable $exception) {
                if ($published && ! $committed) {
                    File::deleteDirectory($root);
                    $this->makeDirectory($root);
                }
                throw $exception;
            } finally {
                File::deleteDirectory($stage);
            }
        });
    }

    private function verifyRestored(string $source, string $root, array $manifest): void
    {
        $marker = $root.'/.restore-pending.json';
        $markerPath = $this->existingPath($marker);
        $this->require(is_file($markerPath) && filesize($markerPath) < 4096, 'No valid pending restore marker.');
        $pending = json_decode(File::get($markerPath), true, 16, JSON_THROW_ON_ERROR);
        $this->require(($pending['version'] ?? null) === 1 && ($pending['manifest_sha256'] ?? null) === hash_file('sha256', $source.'/manifest.json'), 'Pending restore belongs to a different snapshot.');
        foreach ($manifest['files'] as $relative => $details) {
            if (str_starts_with($relative, 'data/')) {
                $actual = $this->fileDetails($this->existingPath($root.'/'.substr($relative, 5)));
                $this->require($actual === $details, 'Restored data does not match snapshot.');
            }
        }
        foreach (self::TABLES as $table) {
            $this->require(DB::table($table)->count() === $manifest['tables'][$table]['rows'], 'Restored table row count does not match snapshot.');
            foreach ($this->rows($source.'/tables/'.$table.'.jsonl') as $row) {
                if (in_array($table, ['objects', 'multipart_parts'], true)) {
                    $row['path'] = rtrim(config('object-store.root'), '/\\').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $row['path']);
                }
                $stored = (array) DB::table($table)->where('id', $row['id'])->first();
                $this->require(array_keys($stored) === array_keys($row) || count($stored) === count($row), 'Restored row is missing.');
                foreach ($row as $column => $value) {
                    $this->require(array_key_exists($column, $stored) && ($value === null ? $stored[$column] === null : (string) $stored[$column] === (string) $value), 'Restored row differs from snapshot.');
                }
            }
        }
        $this->require(File::delete($marker), 'Unable to clear verified restore marker.');
    }

    private function requireEmpty(string $root): void
    {
        foreach (self::TABLES as $table) {
            $this->require(! DB::table($table)->exists(), 'Restore requires empty backup tables, including users.');
        }
        $this->require(count(scandir($root)) === 2, 'Restore requires an empty object directory.');
    }

    private function requireTransactionalDatabase(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $this->require(in_array($driver, ['sqlite', 'mysql', 'mariadb'], true), 'Local restore supports SQLite and MySQL/MariaDB only.');
        if ($driver !== 'sqlite') {
            $tables = array_map(fn (string $table): string => $connection->getTablePrefix().$table, self::TABLES);
            $engines = $connection->query()->fromRaw('information_schema.tables')
                ->where('TABLE_SCHEMA', $connection->getDatabaseName())
                ->whereIn('TABLE_NAME', $tables)
                ->pluck('ENGINE');
            $this->require($engines->count() === count(self::TABLES) && $engines->every(fn (?string $engine): bool => strtoupper($engine ?? '') === 'INNODB'), 'Restore tables must all use transactional InnoDB storage.');
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        sort($columns);

        return $columns;
    }

    /** @return array{version: int, key_fingerprint: string, tables: array<string, array{columns: list<string>, rows: int}>, files: array<string, array{size: int, sha256: string}>} */
    private function manifest(string $directory): array
    {
        $path = $this->existingPath($directory.'/manifest.json');
        $this->require(is_file($path) && filesize($path) <= self::MAX_MANIFEST_BYTES, 'Invalid manifest size.');
        $manifest = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        $this->require(is_array($manifest) && ($manifest['version'] ?? null) === 1, 'Unsupported backup format.');
        $this->require(is_string($manifest['key_fingerprint'] ?? null) && hash_equals($this->keyFingerprint(), $manifest['key_fingerprint']), 'Application key fingerprint does not match.');
        $this->require(is_array($manifest['tables'] ?? null) && array_keys($manifest['tables']) === self::TABLES, 'Invalid backup table inventory.');
        $this->require(is_array($manifest['files'] ?? null) && count($manifest['files']) <= self::MAX_FILES + count(self::TABLES), 'Invalid backup file inventory.');

        return $manifest;
    }

    /** @param array{tables: array<string, array{columns: list<string>, rows: int}>, files: array<string, array{size: int, sha256: string}>} $manifest */
    private function verify(string $directory, array $manifest): void
    {
        $tableFiles = array_map(fn (string $table): string => 'tables/'.$table.'.jsonl', self::TABLES);
        foreach ($manifest['files'] as $relative => $details) {
            $this->relativePath($relative);
            $this->require(str_starts_with($relative, 'data/') || in_array($relative, $tableFiles, true), 'Unexpected backup file.');
            $this->require(is_array($details) && is_int($details['size'] ?? null) && $details['size'] >= 0 && is_string($details['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $details['sha256']) === 1, 'Invalid file checksum entry.');
            $path = $this->existingPath($directory.'/'.$relative);
            $actual = $this->fileDetails($path);
            $this->require(is_file($path) && $actual['size'] === $details['size'] && hash_equals($actual['sha256'], $details['sha256']), 'Backup checksum mismatch: '.$relative);
        }
        $referenced = [];
        foreach (self::TABLES as $table) {
            $definition = $manifest['tables'][$table];
            $columns = $this->columns($table);
            $this->require(is_array($definition) && ($definition['columns'] ?? null) === $columns && is_int($definition['rows'] ?? null), 'Backup schema mismatch: '.$table);
            $this->require(isset($manifest['files']['tables/'.$table.'.jsonl']), 'Missing backup table file.');
            $count = 0;
            foreach ($this->rows($directory.'/tables/'.$table.'.jsonl') as $row) {
                $keys = array_keys($row);
                sort($keys);
                $this->require($keys === $columns, 'Invalid backup row columns.');
                foreach ($row as $value) {
                    $this->require(is_scalar($value) || $value === null, 'Invalid backup row value.');
                }
                if (in_array($table, ['objects', 'multipart_parts'], true)) {
                    $this->relativePath($row['path']);
                    $file = 'data/'.$row['path'];
                    $this->require(isset($manifest['files'][$file]) && (int) $row['size'] === $manifest['files'][$file]['size'], 'Missing or inconsistent object file.');
                    $referenced[$file] = true;
                }
                $count++;
            }
            $this->require($count === $definition['rows'], 'Backup row count mismatch.');
        }
        foreach ($manifest['files'] as $relative => $details) {
            $this->require(! str_starts_with($relative, 'data/') || isset($referenced[$relative]), 'Unreferenced backup data file.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $this->require(! $entry->isLink(), 'Symbolic links are not allowed.');
            if (! $entry->isDir()) {
                $relative = substr(str_replace('\\', '/', $entry->getPathname()), strlen($directory) + 1);
                $this->require($entry->isFile() && ($relative === 'manifest.json' || isset($manifest['files'][$relative])), 'Unexpected backup content.');
            }
        }
    }

    /** @return Generator<int, array<string, scalar|null>> */
    private function rows(string $path): Generator
    {
        $stream = $this->open($path, 'rb');
        try {
            while (($line = fgets($stream, self::MAX_ROW_BYTES)) !== false) {
                $this->require(str_ends_with($line, "\n"), 'Truncated or oversized backup row.');
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                $this->require(is_array($row) && ! array_is_list($row), 'Invalid backup row.');
                yield $row;
            }
            $this->require(feof($stream), 'Unable to read backup table.');
        } finally {
            fclose($stream);
        }
    }

    private function root(): string
    {
        $root = $this->existingPath((string) config('object-store.root'));
        $public = $this->existingPath(public_path());
        $this->require(is_dir($root) && ! $this->inside($root, $public) && ! $this->inside($public, $root), 'Object root must be a private directory.');

        return $root;
    }

    private function archivePath(string $directory, string $root, bool $exists): string
    {
        $directory = str_replace('\\', '/', $directory);
        $this->require($directory !== '' && ! str_contains($directory, "\0"), 'Invalid backup directory.');
        $parent = $this->existingPath(dirname($directory));
        $name = basename($directory);
        $this->require($name !== '.' && $name !== '..' && preg_match('/^[a-zA-Z0-9_-][a-zA-Z0-9._-]*$/D', $name) === 1, 'Invalid backup directory name.');
        $path = $parent.'/'.$name;
        $public = $this->existingPath(public_path());
        $this->require(! $this->inside($path, $public) && ! $this->inside($public, $path) && ! $this->inside($path, $root) && ! $this->inside($root, $path), 'Backup directory must be separate from public and object directories.');
        if ($exists) {
            $path = $this->existingPath($path);
            $this->require(is_dir($path), 'Backup directory does not exist.');
        } else {
            $this->require(! file_exists($path) && ! is_link($path), 'Backup target already exists.');
        }

        return $path;
    }

    private function existingPath(string $path): string
    {
        $this->require($path !== '' && ! str_contains($path, "\0"), 'Invalid filesystem path.');
        $path = str_replace('\\', '/', $path);
        $this->require(! in_array('..', explode('/', $path), true), 'Parent traversal is not allowed.');
        $probe = $path;
        while ($probe !== '.' && dirname($probe) !== $probe) {
            $this->require(! is_link($probe), 'Symbolic links are not allowed.');
            $probe = dirname($probe);
        }
        $resolved = realpath($path);
        $this->require($resolved !== false, 'Required filesystem path does not exist.');

        return rtrim(str_replace('\\', '/', $resolved), '/');
    }

    private function inside(string $path, string $parent): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $parent = strtolower($parent);
        }

        return $path === $parent || str_starts_with($path, $parent.'/');
    }

    private function relativePath(mixed $path): void
    {
        $this->require(is_string($path) && preg_match('/^[a-zA-Z0-9_-][a-zA-Z0-9._\/-]*$/D', $path) === 1, 'Invalid relative file path.');
        foreach (explode('/', $path) as $component) {
            $this->require($component !== '' && $component !== '.' && $component !== '..' && ! str_ends_with($component, '.') && preg_match('/^(CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(?:\.|$)/i', $component) !== 1, 'Unsafe relative file path.');
        }
    }

    private function keyFingerprint(): string
    {
        $key = (string) config('app.key');
        $this->require($key !== '', 'Application key is required.');

        return hash('sha256', $key);
    }

    /** @return array{size: int, sha256: string} */
    private function fileDetails(string $path): array
    {
        clearstatcache(true, $path);
        $this->require(is_file($path), 'Backup file is missing or not a regular file.');
        $size = filesize($path);
        $hash = hash_file('sha256', $path);
        $this->require($size !== false && $hash !== false, 'Unable to hash backup file.');

        return ['size' => $size, 'sha256' => $hash];
    }

    private function copyStream(string $source, string $target): void
    {
        $this->makeDirectory(dirname($target));
        $input = $this->open($source, 'rb');
        try {
            $output = $this->open($target, 'xb');
            try {
                $copied = stream_copy_to_stream($input, $output);
                $this->require($copied !== false && $copied === filesize($source), 'Unable to copy complete backup file.');
            } finally {
                fclose($output);
            }
        } finally {
            fclose($input);
        }
    }

    private function makeDirectory(string $path): void
    {
        $this->require(is_dir($path) || mkdir($path, 0700, true), 'Unable to create backup directory.');
    }

    /** @return resource */
    private function open(string $path, string $mode): mixed
    {
        $stream = fopen($path, $mode);
        $this->require($stream !== false, 'Unable to open backup file.');
        if ($mode === 'xb') {
            chmod($path, 0600);
        }

        return $stream;
    }

    /** @param resource $stream */
    private function write(mixed $stream, string $content): void
    {
        $offset = 0;
        while ($offset < strlen($content)) {
            $written = fwrite($stream, substr($content, $offset));
            $this->require($written !== false && $written > 0, 'Unable to write backup file.');
            $offset += $written;
        }
    }

    private function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    }
}
