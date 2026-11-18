<?php

namespace Tests\Feature;

use App\Models\AccessKey;
use App\Models\Bucket;
use App\Models\User;
use App\Services\ObjectPublisher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ObjectStoreBackupTest extends TestCase
{
    use RefreshDatabase;

    private string $workspace;

    private string $root;

    private string $backup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = str_replace('\\', '/', storage_path('framework/testing-backup-'.bin2hex(random_bytes(8))));
        $this->root = $this->workspace.'/objects';
        $this->backup = $this->workspace.'/snapshot';
        File::ensureDirectoryExists($this->root);
        config(['object-store.root' => str_replace('/', DIRECTORY_SEPARATOR, $this->root)]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        parent::tearDown();
    }

    public function test_backup_verify_and_restore_preserve_files_credentials_and_metadata_at_a_new_root(): void
    {
        $owner = User::factory()->create();
        $password = $owner->getRawOriginal('password');
        $encrypted = Crypt::encryptString('preserved-secret');
        AccessKey::create(['access_key' => 'local-test-key', 'secret_encrypted' => $encrypted, 'scopes' => ['read', 'write'], 'bucket_names' => ['media']]);
        DB::table('audit_events')->insert(['action' => 'test.backup', 'context' => '{"unicode":"ሰላም"}']);
        $bucket = $this->objects(27);
        $upload = $bucket->multipartUploads()->create(['key' => 'pending', 'upload_id' => 'test-upload']);
        File::ensureDirectoryExists($this->root.'/multipart/test-upload');
        File::put($this->root.'/multipart/test-upload/1', 'part');
        $upload->parts()->create(['part_number' => 1, 'path' => $this->root.'/multipart/test-upload/1', 'size' => 4, 'etag' => md5('part')]);
        File::ensureDirectoryExists($this->root.'/.recovery');

        $this->artisan('object-store:backup', ['directory' => $this->backup])->assertSuccessful();
        $this->artisan('object-store:restore', ['directory' => $this->backup, '--verify-only' => true])->assertSuccessful();
        $this->assertDatabaseCount('objects', 27);
        $manifest = File::get($this->backup.'/manifest.json');
        $this->assertStringNotContainsString(config('app.key'), $manifest);
        $this->assertStringNotContainsString('preserved-secret', File::get($this->backup.'/tables/access_keys.jsonl'));
        $this->assertFileDoesNotExist($this->backup.'/tables/sessions.jsonl');
        $this->emptyDatabase();
        $this->root = $this->workspace.'/relocated';
        File::ensureDirectoryExists($this->root);
        config(['object-store.root' => str_replace('/', DIRECTORY_SEPARATOR, $this->root)]);

        $this->artisan('object-store:restore', ['directory' => $this->backup, '--force' => true])->assertSuccessful();

        $this->assertDatabaseCount('objects', 27);
        $this->assertDatabaseHas('objects', ['key' => 'key-0', 'path' => config('object-store.root').DIRECTORY_SEPARATOR.$bucket->id.DIRECTORY_SEPARATOR.hash('sha256', 'key-0'), 'deleted_at' => '2026-01-01 00:00:00']);
        $this->assertSame("payload-0\0", File::get(DB::table('objects')->where('key', 'key-0')->value('path')));
        $this->assertSame('part', File::get(DB::table('multipart_parts')->value('path')));
        $this->assertDatabaseHas('users', ['email' => $owner->email, 'password' => $password, 'remember_token' => null]);
        $this->assertDatabaseHas('access_keys', ['secret_encrypted' => $encrypted]);
        $this->assertSame('preserved-secret', Crypt::decryptString(DB::table('access_keys')->value('secret_encrypted')));
        $this->assertDatabaseHas('audit_events', ['action' => 'test.backup']);

        $active = $bucket->objects()->where('key', 'key-1')->firstOrFail();
        $stage = $active->path.'.'.Str::uuid().'.upload';
        File::put($stage, 'replacement');
        $published = app(ObjectPublisher::class)->publish($bucket, 'key-1', $stage, ['size' => 11, 'etag' => md5('replacement'), 'content_type' => 'text/plain', 'metadata' => [], 'is_public' => false, 'deleted_at' => null]);
        $this->assertSame('replacement', File::get($published->path));
        $trashPath = DB::table('objects')->where('key', 'key-0')->value('path');
        $this->artisan('object-store:purge')->assertSuccessful();
        $this->assertDatabaseMissing('objects', ['key' => 'key-0']);
        $this->assertFileDoesNotExist($trashPath);
    }

    public function test_pending_restore_is_only_cleared_after_files_and_rows_match_the_snapshot(): void
    {
        $bucket = $this->objects();
        $this->artisan('object-store:backup', ['directory' => $this->backup])->assertSuccessful();
        $this->emptyDestination();
        $this->artisan('object-store:restore', ['directory' => $this->backup, '--force' => true])->assertSuccessful();
        $marker = $this->root.'/.restore-pending.json';
        File::put($marker, json_encode(['version' => 1, 'manifest_sha256' => hash_file('sha256', $this->backup.'/manifest.json')], JSON_THROW_ON_ERROR));
        $path = DB::table('objects')->first()->path;
        File::put($path, 'corrupted');

        $this->artisan('object-store:restore', ['directory' => $this->backup, '--verify-restored' => true])->assertFailed();
        $this->assertFileExists($marker);
        File::put($path, "payload-0\0");
        $this->artisan('object-store:restore', ['directory' => $this->backup, '--verify-restored' => true])->assertSuccessful();
        $this->assertFileDoesNotExist($marker);
        $this->assertDatabaseCount('objects', 1);
    }

    public function test_tampered_file_is_refused_before_any_database_or_destination_writes(): void
    {
        $bucket = $this->objects();
        $this->artisan('object-store:backup', ['directory' => $this->backup])->assertSuccessful();
        File::put($this->backup.'/data/'.$bucket->id.'/'.hash('sha256', 'key-0'), 'corrupt');
        $this->emptyDestination();

        $this->artisan('object-store:restore', ['directory' => $this->backup, '--verify-only' => true])->assertFailed();
        $this->artisan('object-store:restore', ['directory' => $this->backup, '--force' => true])->assertFailed();

        $this->assertDatabaseEmpty('buckets');
        $this->assertSame(['.', '..'], scandir($this->root));
        $this->assertSame([], glob($this->workspace.'/.object-store-restore-*'));
    }

    public function test_force_refuses_existing_users_or_object_files(): void
    {
        $this->objects();
        $this->artisan('object-store:backup', ['directory' => $this->backup])->assertSuccessful();
        $this->emptyDestination();
        $owner = User::factory()->create();

        $this->artisan('object-store:restore', ['directory' => $this->backup, '--force' => true])->expectsOutput('Restore requires empty backup tables, including users.')->assertFailed();
        $this->assertModelExists($owner);
        $this->emptyDatabase();
        File::put($this->root.'/existing', 'keep');
        $this->artisan('object-store:restore', ['directory' => $this->backup, '--force' => true])->expectsOutput('Restore requires an empty object directory.')->assertFailed();
        $this->assertSame('keep', File::get($this->root.'/existing'));
    }

    public function test_mismatched_application_key_is_refused(): void
    {
        $this->artisan('object-store:backup', ['directory' => $this->backup])->assertSuccessful();
        config(['app.key' => 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=']);

        $this->artisan('object-store:restore', ['directory' => $this->backup, '--verify-only' => true])->expectsOutput('Application key fingerprint does not match.')->assertFailed();
    }

    public function test_backup_refuses_existing_public_and_object_root_targets(): void
    {
        File::ensureDirectoryExists($this->backup);
        File::put($this->backup.'/keep', 'keep');

        $this->artisan('object-store:backup', ['directory' => $this->backup])->assertFailed();
        $this->artisan('object-store:backup', ['directory' => public_path('unsafe-backup')])->assertFailed();
        $this->artisan('object-store:backup', ['directory' => $this->root.'/snapshot'])->assertFailed();

        $this->assertSame('keep', File::get($this->backup.'/keep'));
        $this->assertDirectoryDoesNotExist($this->root.'/snapshot');
        $this->assertDirectoryDoesNotExist(public_path('unsafe-backup'));
    }

    public function test_backup_refuses_pending_recovery_journals(): void
    {
        File::ensureDirectoryExists($this->root.'/.recovery');
        File::put($this->root.'/.recovery/pending.json', '{}');

        $this->artisan('object-store:backup', ['directory' => $this->backup])->expectsOutput('Resolve recovery journals before backup.')->assertFailed();

        $this->assertDirectoryDoesNotExist($this->backup);
        $this->assertFileExists($this->root.'/.recovery/pending.json');
    }

    public function test_backup_refuses_recovery_prior_debris_without_a_journal(): void
    {
        File::ensureDirectoryExists($this->root.'/.recovery');
        File::put($this->root.'/.recovery/orphan.prior', 'old bytes');

        $this->artisan('object-store:backup', ['directory' => $this->backup])->expectsOutput('Resolve recovery journals before backup.')->assertFailed();

        $this->assertDirectoryDoesNotExist($this->backup);
        $this->assertSame('old bytes', File::get($this->root.'/.recovery/orphan.prior'));
    }

    public function test_missing_object_file_aborts_backup_and_cleans_partial_directory(): void
    {
        $bucket = $this->objects();
        File::delete($bucket->objects()->firstOrFail()->path);

        $this->artisan('object-store:backup', ['directory' => $this->backup])->assertFailed();

        $this->assertDirectoryDoesNotExist($this->backup);
        $this->assertDatabaseCount('objects', 1);
    }

    public function test_traversal_in_a_rehashed_table_is_refused(): void
    {
        $this->objects();
        $this->artisan('object-store:backup', ['directory' => $this->backup])->assertSuccessful();
        $row = json_decode(File::get($this->backup.'/tables/objects.jsonl'), true, 512, JSON_THROW_ON_ERROR);
        $row['path'] = '../escape';
        $this->replaceTable('objects', [$row]);
        $this->emptyDestination();

        $this->artisan('object-store:restore', ['directory' => $this->backup, '--force' => true])->assertFailed();

        $this->assertDatabaseEmpty('objects');
        $this->assertSame(['.', '..'], scandir($this->root));
        $this->assertFileDoesNotExist($this->workspace.'/escape');
    }

    public function test_database_failure_rolls_back_inserted_rows_and_cleans_staging_files(): void
    {
        User::factory()->create();
        $this->objects();
        $this->artisan('object-store:backup', ['directory' => $this->backup])->assertSuccessful();
        $this->emptyDestination();
        DB::listen(function (QueryExecuted $query): void {
            if (preg_match('/^insert into ["`]?objects["`]?\s/i', $query->sql) === 1) {
                throw new RuntimeException('Simulated restore database failure.');
            }
        });

        $this->artisan('object-store:restore', ['directory' => $this->backup, '--force' => true])->assertFailed();

        $this->assertDatabaseEmpty('users');
        $this->assertDatabaseEmpty('buckets');
        $this->assertDatabaseEmpty('objects');
        $this->assertSame(['.', '..'], scandir($this->root));
        $this->assertSame([], glob($this->workspace.'/.object-store-restore-*'));
    }

    public function test_invalid_utf8_aborts_backup_and_removes_partial_directory(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite permits corrupt UTF-8 fixture bytes; MySQL rejects them before backup.');
        }

        DB::table('audit_events')->insert(['action' => "invalid-\xFF"]);

        $this->artisan('object-store:backup', ['directory' => $this->backup])->assertFailed();

        $this->assertDirectoryDoesNotExist($this->backup);
        $this->assertDatabaseCount('audit_events', 1);
    }

    public function test_symlinked_backup_file_is_refused(): void
    {
        $bucket = $this->objects();
        $this->artisan('object-store:backup', ['directory' => $this->backup])->assertSuccessful();
        $file = $this->backup.'/data/'.$bucket->id.'/'.hash('sha256', 'key-0');
        File::move($file, $this->workspace.'/linked-content');
        if (! @symlink($this->workspace.'/linked-content', $file)) {
            $this->markTestSkipped('Creating symbolic links is unavailable on this host.');
        }

        $this->artisan('object-store:restore', ['directory' => $this->backup, '--verify-only' => true])->expectsOutput('Symbolic links are not allowed.')->assertFailed();
    }

    private function objects(int $count = 1): Bucket
    {
        $bucket = Bucket::create(['name' => 'media', 'quota_bytes' => 100000]);
        File::ensureDirectoryExists($this->root.'/'.$bucket->id);
        for ($index = 0; $index < $count; $index++) {
            $payload = 'payload-'.$index."\0";
            $path = $this->root.'/'.$bucket->id.'/'.hash('sha256', 'key-'.$index);
            File::put($path, $payload);
            $bucket->objects()->create(['key' => 'key-'.$index, 'path' => $path, 'size' => strlen($payload), 'etag' => md5($payload), 'metadata' => ['test' => 'yes'], 'deleted_at' => $index === 0 ? '2026-01-01 00:00:00' : null]);
        }

        return $bucket;
    }

    private function emptyDatabase(): void
    {
        foreach (['multipart_parts', 'multipart_uploads', 'objects', 'buckets', 'access_keys', 'audit_events', 'users'] as $table) {
            DB::table($table)->delete();
        }
    }

    private function emptyDestination(): void
    {
        $this->emptyDatabase();
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root);
    }

    /** @param list<array<string, mixed>> $rows */
    private function replaceTable(string $table, array $rows): void
    {
        $path = $this->backup.'/tables/'.$table.'.jsonl';
        File::put($path, implode('', array_map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR)."\n", $rows)));
        $manifest = json_decode(File::get($this->backup.'/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['files']['tables/'.$table.'.jsonl'] = ['size' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        File::put($this->backup.'/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
    }
}
