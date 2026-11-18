<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ObjectWriteConcurrencyTest extends TestCase
{
    public function test_competing_processes_cannot_overfill_a_bucket(): void
    {
        $workspace = storage_path('framework/testing-concurrency-'.Str::uuid());
        $root = $workspace.DIRECTORY_SEPARATOR.'objects';
        $database = $workspace.DIRECTORY_SEPARATOR.'database.sqlite';
        File::ensureDirectoryExists($root);
        File::put($database, '');
        $connection = config('database.connections.'.config('database.default'));
        $mysql = in_array($connection['driver'], ['mysql', 'mariadb'], true);
        config(['database.connections.race' => $mysql ? $connection : ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true]]);
        $processes = [];
        $bucket = null;
        try {
            $this->artisan('migrate', ['--database' => 'race', '--force' => true])->assertExitCode(0);
            $bucket = DB::connection('race')->table('buckets')->insertGetId(['name' => 'competing-'.Str::uuid(), 'quota_bytes' => 8]);
            File::ensureDirectoryExists($root.DIRECTORY_SEPARATOR.$bucket);
            foreach (['first', 'second'] as $key) {
                $stage = $root.DIRECTORY_SEPARATOR.$bucket.DIRECTORY_SEPARATOR.hash('sha256', $key).'.'.Str::uuid().'.upload';
                File::put($stage, 'abcdef');
                $process = new Process([PHP_BINARY, base_path('tests/Fixtures/concurrent-write.php'), $key, $stage, (string) $bucket], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => $mysql ? $connection['driver'] : 'sqlite', 'DB_DATABASE' => $mysql ? $connection['database'] : $database, 'DB_HOST' => (string) ($connection['host'] ?? '127.0.0.1'), 'DB_PORT' => (string) ($connection['port'] ?? 3306), 'DB_USERNAME' => (string) ($connection['username'] ?? ''), 'DB_PASSWORD' => (string) ($connection['password'] ?? ''), 'DB_URL' => '', 'OBJECT_STORE_PATH' => $root, 'CACHE_STORE' => 'array']);
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }
            $outputs = [];
            foreach ($processes as $process) {
                $process->wait();
                $outputs[] = $process->getOutput();
            }
            sort($outputs);
            $this->assertSame(['published', 'rejected:413'], $outputs);
            $this->assertSame(1, DB::connection('race')->table('objects')->where('bucket_id', $bucket)->count());
            $this->assertSame(6, (int) DB::connection('race')->table('objects')->where('bucket_id', $bucket)->sum('size'));
            $this->assertSame([], glob($root.DIRECTORY_SEPARATOR.'.recovery'.DIRECTORY_SEPARATOR.'*.json'));
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            if ($bucket !== null) {
                DB::connection('race')->table('buckets')->where('id', $bucket)->delete();
            }
            DB::purge('race');
            File::deleteDirectory($workspace);
        }
    }
}
