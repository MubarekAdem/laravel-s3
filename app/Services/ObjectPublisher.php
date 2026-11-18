<?php

namespace App\Services;

use App\Models\Bucket;
use App\Models\ObjectRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ObjectPublisher
{
    private const ATTRIBUTES = ['path', 'size', 'etag', 'content_type', 'metadata', 'is_public', 'deleted_at'];

    public function __construct(private StorageMutex $mutex) {}

    public function publish(Bucket $bucket, string $key, string $staged, array $attributes): ObjectRecord
    {
        return $this->mutex->run(function () use ($bucket, $key, $staged, $attributes): ObjectRecord {
            $canonical = $this->canonical($bucket->id, $key);
            $this->safePath($canonical);
            $this->validateStage($staged, $canonical);
            foreach ($this->journals() as $pending) {
                if ($this->readJournal($pending)['canonical'] === $canonical) {
                    throw new RuntimeException('Object has an unresolved journal. Run object-store:recover-writes.');
                }
            }
            $journal = null;
            $transactionLevel = DB::transactionLevel();
            try {
                $object = DB::transaction(function () use ($bucket, $key, $staged, $attributes, $canonical, &$journal): ObjectRecord {
                    $locked = Bucket::query()->lockForUpdate()->findOrFail($bucket->id);
                    $size = File::size($staged);
                    abort_if($size !== (int) $attributes['size'] || $size > config('object-store.max_bytes'), 413, 'Invalid object size.');
                    $oldSize = $locked->objects()->where('key', $key)->whereNull('deleted_at')->value('size') ?? 0;
                    $used = $locked->objects()->whereNull('deleted_at')->sum('size');
                    abort_if($locked->quota_bytes !== null && $used - $oldSize + $size > $locked->quota_bytes, 413, 'Bucket quota would be exceeded.');
                    $previous = $locked->objects()->where('key', $key)->first();
                    if (($previous !== null && ($previous->path !== $canonical || ! File::isFile($canonical))) || ($previous === null && File::exists($canonical))) {
                        throw new RuntimeException('Existing object path or bytes do not match the database.');
                    }
                    $base = $this->directory().DIRECTORY_SEPARATOR.Str::uuid();
                    File::ensureDirectoryExists(dirname($base));
                    $this->safePath($base.'.json');
                    $attributes = array_intersect_key($attributes, array_flip(self::ATTRIBUTES));
                    $attributes['path'] = $canonical;
                    $attributes = $this->snapshot((new ObjectRecord)->forceFill($attributes));
                    $entry = ['version' => 1, 'bucket' => (int) $bucket->id, 'key' => $key, 'canonical' => $canonical, 'staged' => $staged, 'backup' => $base.'.prior', 'new' => $attributes, 'previous' => $this->snapshot($previous), 'sha256' => $this->checksum($staged), 'prior_sha256' => $previous === null ? null : $this->checksum($canonical)];
                    if ($previous !== null) {
                        $this->requireSuccess(File::copy($canonical, $entry['backup']), 'Could not back up prior bytes.');
                        $this->verify($entry['backup'], $entry['prior_sha256']);
                    }
                    $json = json_encode($entry, JSON_THROW_ON_ERROR);
                    if (strlen($json) > 1024 * 1024) {
                        throw new RuntimeException('Object journal exceeds the size limit.');
                    }
                    File::replace($base.'.json', $json, 0600);
                    $journal = $base.'.json';
                    if (File::get($journal) !== $json) {
                        throw new RuntimeException('Could not persist object journal.');
                    }
                    $this->requireSuccess(File::move($staged, $canonical), 'Could not publish staged object.');
                    $this->verify($canonical, $entry['sha256']);

                    $object = $locked->objects()->updateOrCreate(['key' => $key], $attributes);
                    if ($this->snapshot($object->fresh()) !== $attributes) {
                        throw new RuntimeException('Object attributes were not persisted.');
                    }

                    return $object;
                });
            } catch (Throwable $exception) {
                if ($journal !== null) {
                    try {
                        if ($transactionLevel === 0 && DB::transactionLevel() === 0 && DB::connection()->getPdo()->inTransaction()) {
                            DB::connection()->getPdo()->rollBack();
                        }
                        $this->recover($journal);
                    } catch (Throwable $recoveryException) {
                        throw new RuntimeException('Write failed; recovery remains pending: '.$journal, 0, $recoveryException);
                    }
                }
                throw $exception;
            }
            $this->recover($journal);

            return $object;
        });
    }

    public function journals(): \Generator
    {
        $directory = $this->directory();
        $this->safePath($directory);
        if (! File::isDirectory($directory)) {
            return;
        }
        foreach (new \FilesystemIterator($directory) as $file) {
            if ($file->getExtension() === 'json') {
                yield $file->getPathname();
            }
        }
    }

    public function discardStage(string $path): void
    {
        $this->mutex->run(function () use ($path): void {
            $this->safePath($path);
            foreach ($this->journals() as $journal) {
                try {
                    if ($this->readJournal($journal)['staged'] === $path) {
                        return;
                    }
                } catch (Throwable) {
                    return;
                }
            }
            $this->remove($path);
        });
    }

    public function recover(string $journal, bool $dryRun = false): string
    {
        return $this->mutex->run(function () use ($journal, $dryRun): string {
            $entry = $this->readJournal($journal);

            return DB::transaction(function () use ($entry, $journal, $dryRun): string {
                Bucket::query()->lockForUpdate()->findOrFail($entry['bucket']);
                $current = $this->snapshot(ObjectRecord::query()->where('bucket_id', $entry['bucket'])->where('key', $entry['key'])->first());
                $committed = $current === $entry['new'];
                if (! $committed && $current !== $entry['previous']) {
                    throw new RuntimeException('Database matches neither journal version.');
                }
                foreach (['staged' => 'sha256', 'backup' => 'prior_sha256'] as $path => $hash) {
                    if (File::exists($entry[$path])) {
                        $this->verify($entry[$path], $entry[$hash]);
                    }
                }
                $canonical = $entry['canonical'];
                $target = File::exists($canonical) ? $this->checksum($canonical) : null;
                if ($committed) {
                    $this->verify($canonical, $entry['sha256']);
                } else {
                    if ($target !== null && $target !== $entry['sha256'] && $target !== $entry['prior_sha256']) {
                        throw new RuntimeException('Unexplained canonical object content.');
                    }
                    if ($entry['prior_sha256'] !== null && $target !== $entry['prior_sha256']) {
                        $this->verify($entry['backup'], $entry['prior_sha256']);
                        if (! $dryRun) {
                            $restore = $entry['backup'].'.restore';
                            $this->safePath($restore);
                            $this->requireSuccess(File::copy($entry['backup'], $restore), 'Could not stage prior bytes.');
                            $this->verify($restore, $entry['prior_sha256']);
                            $this->requireSuccess(File::move($restore, $canonical), 'Could not restore prior bytes.');
                            $this->verify($canonical, $entry['prior_sha256']);
                        }
                    } elseif ($entry['prior_sha256'] === null && $target !== null && ! $dryRun) {
                        $this->remove($canonical);
                    }
                }
                if (! $dryRun) {
                    $this->remove($entry['staged']);
                    $this->remove($entry['backup']);
                    $this->remove($journal);
                }

                return $committed ? 'committed' : 'rolled back';
            });
        });
    }

    private function readJournal(string $path): array
    {
        $this->safePath($path);
        $base = $this->directory().DIRECTORY_SEPARATOR;
        if (! preg_match('/^'.preg_quote($base, '/').'([a-f0-9-]{36})\.json$/D', $path, $matches) || File::size($path) > 1024 * 1024) {
            throw new RuntimeException('Invalid journal path or size.');
        }
        $entry = json_decode(File::get($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($entry) || ($entry['version'] ?? null) !== 1 || ! is_int($entry['bucket'] ?? null) || $entry['bucket'] < 1 || ! is_string($entry['key'] ?? null)) {
            throw new RuntimeException('Invalid recovery journal.');
        }
        $canonical = $this->canonical($entry['bucket'], $entry['key']);
        if (($entry['canonical'] ?? null) !== $canonical || ($entry['backup'] ?? null) !== $base.$matches[1].'.prior' || ! is_string($entry['staged'] ?? null)) {
            throw new RuntimeException('Journal contains unexpected paths.');
        }
        $this->validateStage($entry['staged'], $canonical);
        $this->safePath($canonical);
        $this->safePath($entry['backup']);
        foreach (['new', 'previous'] as $name) {
            if ($name === 'previous' && array_key_exists($name, $entry) && $entry[$name] === null) {
                continue;
            }
            if (! is_array($entry[$name] ?? null) || array_keys($entry[$name]) !== self::ATTRIBUTES || $entry[$name]['path'] !== $canonical) {
                throw new RuntimeException('Invalid journal object attributes.');
            }
        }
        if (! preg_match('/^[a-f0-9]{64}$/D', $entry['sha256'] ?? '') || ! array_key_exists('prior_sha256', $entry) || ($entry['previous'] === null ? $entry['prior_sha256'] !== null : ! preg_match('/^[a-f0-9]{64}$/D', $entry['prior_sha256'] ?? ''))) {
            throw new RuntimeException('Invalid journal checksums.');
        }

        return $entry;
    }

    private function snapshot(?ObjectRecord $object): ?array
    {
        return $object === null ? null : ['path' => $object->path, 'size' => (int) $object->size, 'etag' => $object->etag, 'content_type' => $object->content_type, 'metadata' => $object->metadata, 'is_public' => (bool) $object->is_public, 'deleted_at' => $object->deleted_at?->toISOString()];
    }

    private function canonical(int $bucket, string $key): string
    {
        return config('object-store.root').DIRECTORY_SEPARATOR.$bucket.DIRECTORY_SEPARATOR.hash('sha256', $key);
    }

    private function directory(): string
    {
        return config('object-store.root').DIRECTORY_SEPARATOR.'.recovery';
    }

    private function validateStage(string $path, string $canonical): void
    {
        $this->safePath($path);
        if (! preg_match('/^'.preg_quote($canonical, '/').'\.[a-f0-9-]{36}\.(upload|assembly)$/D', $path)) {
            throw new RuntimeException('Invalid staged object path.');
        }
    }

    private function safePath(string $path): void
    {
        $root = rtrim(config('object-store.root'), '/\\');
        if (! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || is_link($root)) {
            throw new RuntimeException('Unsafe storage root or object path.');
        }
        $cursor = $root;
        foreach (explode(DIRECTORY_SEPARATOR, substr($path, strlen($root) + 1)) as $component) {
            if ($component === '' || $component === '.' || $component === '..' || str_contains($component, '/') || str_contains($component, '\\')) {
                throw new RuntimeException('Invalid storage path component.');
            }
            $cursor .= DIRECTORY_SEPARATOR.$component;
            if (is_link($cursor) || (file_exists($cursor) && ! is_file($cursor) && ! is_dir($cursor))) {
                throw new RuntimeException('Unsafe storage path.');
            }
        }
    }

    private function checksum(string $path): string
    {
        if (! File::isFile($path) || ($hash = hash_file('sha256', $path)) === false) {
            throw new RuntimeException('Could not checksum object bytes: '.$path);
        }

        return $hash;
    }

    private function verify(string $path, ?string $expected): void
    {
        if ($expected === null || ! hash_equals($expected, $this->checksum($path))) {
            throw new RuntimeException('Object checksum does not match journal: '.$path);
        }
    }

    private function remove(string $path): void
    {
        if (File::exists($path)) {
            $this->requireSuccess(File::delete($path), 'Could not remove recovery file: '.$path);
        }
    }

    private function requireSuccess(bool $success, string $message): void
    {
        if (! $success) {
            throw new RuntimeException($message);
        }
    }
}
