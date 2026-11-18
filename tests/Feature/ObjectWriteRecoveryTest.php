<?php

namespace Tests\Feature;

use App\Models\Bucket;
use App\Models\ObjectRecord;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ObjectWriteRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing-object-recovery-'.Str::uuid());
        config(['object-store.root' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_failed_put_restores_existing_bytes_and_database_attributes(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $this->putObject('original')->assertOk();
        $object = $bucket->objects()->firstOrFail();
        $dispatcher = ObjectRecord::getEventDispatcher();
        ObjectRecord::setEventDispatcher(clone $dispatcher);
        ObjectRecord::saving(function (): void {
            throw new \RuntimeException('Simulated database failure');
        });

        try {
            $this->putObject('replacement')->assertServerError();
        } finally {
            ObjectRecord::setEventDispatcher($dispatcher);
        }

        $this->assertSame('original', File::get($object->path));
        $this->assertSame(md5('original'), $object->fresh()->etag);
        $this->assertSame([], File::glob($this->root.DIRECTORY_SEPARATOR.'.recovery'.DIRECTORY_SEPARATOR.'*'));
    }

    public function test_failed_first_put_removes_uncommitted_bytes(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $dispatcher = ObjectRecord::getEventDispatcher();
        ObjectRecord::setEventDispatcher(clone $dispatcher);
        ObjectRecord::saving(function (): void {
            throw new \RuntimeException('Simulated database failure');
        });

        try {
            $this->putObject('replacement')->assertServerError();
        } finally {
            ObjectRecord::setEventDispatcher($dispatcher);
        }

        $this->assertSame(0, $bucket->objects()->count());
        $this->assertFileDoesNotExist($this->canonical($bucket));
    }

    public function test_commit_failure_restores_prior_bytes_and_committed_database_attributes(): void
    {
        DB::rollBack();
        $bucket = Bucket::create(['name' => 'media']);
        $this->putObject('original')->assertOk();
        $connection = DB::connection();
        $dispatcher = $connection->getEventDispatcher();
        $isolatedDispatcher = clone $dispatcher;
        $connection->setEventDispatcher($isolatedDispatcher);
        $failCommit = true;
        $isolatedDispatcher->listen(TransactionCommitting::class, function () use (&$failCommit): void {
            if ($failCommit) {
                $failCommit = false;
                throw new \RuntimeException('Simulated commit failure');
            }
        });

        try {
            $this->putObject('replacement')->assertServerError();
        } finally {
            $connection->setEventDispatcher($dispatcher);
        }

        $this->assertFalse($failCommit);
        $this->assertSame('original', File::get($this->canonical($bucket)));
        $this->assertSame(md5('original'), $bucket->objects()->firstOrFail()->etag);
        $this->assertSame([], File::glob($this->root.DIRECTORY_SEPARATOR.'.recovery'.DIRECTORY_SEPARATOR.'*'));
        $bucket->objects()->delete();
        $bucket->delete();
    }

    public function test_copy_to_itself_stages_bytes_and_keeps_the_canonical_path(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $this->putObject('original')->assertOk();

        $this->withoutMiddleware()->call('PUT', '/s3/media/file.bin', [], [], [], ['HTTP_X_AMZ_COPY_SOURCE' => '/media/file.bin'])->assertOk();

        $object = $bucket->objects()->firstOrFail();
        $this->assertSame($this->canonical($bucket), $object->path);
        $this->assertSame('original', File::get($object->path));
    }

    public function test_quota_rejection_preserves_prior_bytes(): void
    {
        $bucket = Bucket::create(['name' => 'media', 'quota_bytes' => 8]);
        $this->putObject('original')->assertOk();

        $this->putObject('too large for quota')->assertStatus(413);

        $this->assertSame('original', File::get($this->canonical($bucket)));
        $this->assertSame(md5('original'), $bucket->objects()->firstOrFail()->etag);
    }

    public function test_failed_multipart_publish_restores_object_and_retains_upload_for_retry(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $this->putObject('original')->assertOk();
        $upload = $bucket->multipartUploads()->create(['key' => 'file.bin', 'upload_id' => 'retry-upload']);
        $this->withoutMiddleware()->call('PUT', '/s3/media/file.bin?uploadId=retry-upload&partNumber=1', [], [], [], [], 'replacement')->assertOk();
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'.md5('replacement').'</ETag></Part></CompleteMultipartUpload>';
        $dispatcher = ObjectRecord::getEventDispatcher();
        ObjectRecord::setEventDispatcher(clone $dispatcher);
        ObjectRecord::saving(function (): void {
            throw new \RuntimeException('Simulated database failure');
        });

        try {
            $this->withoutMiddleware()->call('POST', '/s3/media/file.bin?uploadId=retry-upload', [], [], [], [], $body)->assertServerError();
        } finally {
            ObjectRecord::setEventDispatcher($dispatcher);
        }

        $this->assertSame('original', File::get($this->canonical($bucket)));
        $this->assertSame(md5('original'), $bucket->objects()->firstOrFail()->etag);
        $this->assertModelExists($upload);
        $this->assertFileExists($upload->parts()->firstOrFail()->path);

        $this->withoutMiddleware()->call('POST', '/s3/media/file.bin?uploadId=retry-upload', [], [], [], [], $body)->assertOk();

        $this->assertSame('replacement', File::get($this->canonical($bucket)));
        $this->assertModelMissing($upload);
    }

    public function test_recovery_dry_run_preserves_files_then_restores_uncommitted_write(): void
    {
        [$journal, $entry] = $this->interruptedWrite();

        $this->artisan('object-store:recover-writes --dry-run')->assertExitCode(0);

        $this->assertSame('replacement', File::get($entry['canonical']));
        $this->assertFileExists($journal);
        $this->assertFileExists($entry['backup']);

        $this->artisan('object-store:recover-writes')->assertExitCode(0);

        $this->assertSame('original', File::get($entry['canonical']));
        $this->assertFileDoesNotExist($journal);
        $this->assertFileDoesNotExist($entry['backup']);
        $this->artisan('object-store:recover-writes')->assertExitCode(0);
    }

    public function test_recovery_cleans_committed_write_without_restoring_old_bytes(): void
    {
        [$journal, $entry] = $this->interruptedWrite();
        ObjectRecord::query()->firstOrFail()->update($entry['new']);

        $this->artisan('object-store:recover-writes')->assertExitCode(0);

        $this->assertSame('replacement', File::get($entry['canonical']));
        $this->assertFileDoesNotExist($journal);
        $this->assertFileDoesNotExist($entry['backup']);
    }

    public function test_recovery_removes_uncommitted_first_write(): void
    {
        [$journal, $entry] = $this->interruptedWrite();
        ObjectRecord::query()->firstOrFail()->delete();
        File::delete($entry['backup']);
        $entry['previous'] = null;
        $entry['prior_sha256'] = null;
        File::put($journal, json_encode($entry, JSON_THROW_ON_ERROR));

        $this->artisan('object-store:recover-writes')->assertExitCode(0);

        $this->assertFileDoesNotExist($entry['canonical']);
        $this->assertFileDoesNotExist($journal);
        $this->assertDatabaseCount('objects', 0);
    }

    public function test_recovery_retains_committed_journal_when_canonical_checksum_is_wrong(): void
    {
        [$journal, $entry] = $this->interruptedWrite();
        ObjectRecord::query()->firstOrFail()->update($entry['new']);
        File::put($entry['canonical'], 'corrupt');

        $this->artisan('object-store:recover-writes')->assertExitCode(1);

        $this->assertFileExists($journal);
        $this->assertFileExists($entry['backup']);
        $this->assertSame('corrupt', File::get($entry['canonical']));
    }

    public function test_recovery_retains_journal_when_database_has_an_unexplained_version(): void
    {
        [$journal, $entry] = $this->interruptedWrite();
        ObjectRecord::query()->firstOrFail()->update(['etag' => md5('another write')]);

        $this->artisan('object-store:recover-writes')->assertExitCode(1);

        $this->assertFileExists($journal);
        $this->assertSame('replacement', File::get($entry['canonical']));
        $this->assertFileExists($entry['backup']);
    }

    public function test_recovery_retains_journal_and_bytes_when_content_is_unexplained(): void
    {
        [$journal, $entry] = $this->interruptedWrite();
        File::put($entry['canonical'], 'unexplained');

        $this->artisan('object-store:recover-writes')->assertExitCode(1);

        $this->assertSame('unexplained', File::get($entry['canonical']));
        $this->assertFileExists($journal);
        $this->assertSame('original', File::get($entry['backup']));
    }

    public function test_recovery_rejects_forged_backup_path(): void
    {
        [$journal, $entry] = $this->interruptedWrite();
        $entry['backup'] = $entry['canonical'];
        File::put($journal, json_encode($entry, JSON_THROW_ON_ERROR));

        $this->artisan('object-store:recover-writes')->assertExitCode(1);

        $this->assertFileExists($journal);
        $this->assertSame('replacement', File::get($entry['canonical']));
    }

    public function test_failed_recovery_preserves_evidence_for_a_later_retry(): void
    {
        $bucket = Bucket::create(['name' => 'media']);
        $this->putObject('original')->assertOk();
        $dispatcher = ObjectRecord::getEventDispatcher();
        ObjectRecord::setEventDispatcher(clone $dispatcher);
        ObjectRecord::saving(function (): void {
            foreach (File::glob($this->root.DIRECTORY_SEPARATOR.'.recovery'.DIRECTORY_SEPARATOR.'*.prior') as $backup) {
                File::put($backup, 'corrupted');
            }
            throw new \RuntimeException('Simulated database failure');
        });

        try {
            $this->putObject('replacement')->assertServerError();
        } finally {
            ObjectRecord::setEventDispatcher($dispatcher);
        }

        $journals = File::glob($this->root.DIRECTORY_SEPARATOR.'.recovery'.DIRECTORY_SEPARATOR.'*.json');
        $this->assertCount(1, $journals);
        $this->assertSame(md5('original'), $bucket->objects()->firstOrFail()->etag);
        $this->artisan('object-store:recover-writes')->assertExitCode(1);
        $entry = json_decode(File::get($journals[0]), true, flags: JSON_THROW_ON_ERROR);
        File::put($entry['backup'], 'original');

        $this->artisan('object-store:recover-writes')->assertExitCode(0);

        $this->assertSame('original', File::get($entry['canonical']));
        $this->assertFileDoesNotExist($journals[0]);
    }

    private function putObject(string $body): TestResponse
    {
        return $this->withoutMiddleware()->call('PUT', '/s3/media/file.bin', [], [], [], [], $body);
    }

    private function canonical(Bucket $bucket): string
    {
        return $this->root.DIRECTORY_SEPARATOR.$bucket->id.DIRECTORY_SEPARATOR.hash('sha256', 'file.bin');
    }

    private function interruptedWrite(): array
    {
        $bucket = Bucket::create(['name' => 'media']);
        $this->putObject('original')->assertOk();
        $object = $bucket->objects()->firstOrFail();
        $previous = ['path' => $object->path, 'size' => 8, 'etag' => md5('original'), 'content_type' => $object->content_type, 'metadata' => $object->metadata, 'is_public' => false, 'deleted_at' => null];
        $new = array_replace($previous, ['size' => 11, 'etag' => md5('replacement')]);
        $base = $this->root.DIRECTORY_SEPARATOR.'.recovery'.DIRECTORY_SEPARATOR.Str::uuid();
        $entry = ['version' => 1, 'bucket' => $bucket->id, 'key' => 'file.bin', 'canonical' => $object->path, 'staged' => $object->path.'.'.Str::uuid().'.upload', 'backup' => $base.'.prior', 'new' => $new, 'previous' => $previous, 'sha256' => hash('sha256', 'replacement'), 'prior_sha256' => hash('sha256', 'original')];
        File::put($entry['backup'], 'original');
        File::put($entry['canonical'], 'replacement');
        File::put($base.'.json', json_encode($entry, JSON_THROW_ON_ERROR));

        return [$base.'.json', $entry];
    }
}
