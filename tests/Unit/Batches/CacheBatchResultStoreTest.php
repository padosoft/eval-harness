<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Batches;

use Closure;
use Illuminate\Cache\Repository as IlluminateCacheRepository;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Store;
use Padosoft\EvalHarness\Batches\CacheBatchResultStore;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Tests\TestCase;

final class CacheBatchResultStoreTest extends TestCase
{
    private CacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CacheFactory $cacheFactory */
        $cacheFactory = $this->app->make(CacheFactory::class);
        $this->cache = $cacheFactory->store('array');
    }

    public function test_successful_outputs_reports_invalid_cached_output_payloads(): void
    {
        $store = $this->store();
        $store->start('invalid-output', 1, 60);
        $this->cache->put($this->resultKey('invalid-output', 0), [
            'status' => 'success',
            'sample_id' => 's1',
            'actual_output' => ['not-a-string'],
        ], 60);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Stored lazy parallel batch output for index 0 is invalid');

        $store->successfulResults('invalid-output', 1, [0]);
    }

    public function test_reads_report_invalid_cached_batch_metadata(): void
    {
        $store = $this->store();
        $this->cache->put($this->metaKey('invalid-meta'), [
            'sample_count' => 1,
            'status' => 'unknown',
        ], 60);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Stored lazy parallel batch metadata for batch 'invalid-meta' is invalid");

        $store->successfulResults('invalid-meta', 1, [0]);
    }

    public function test_failures_reports_invalid_cached_failure_payloads(): void
    {
        $store = $this->store();
        $store->start('invalid-failure', 1, 60);
        $this->cache->put($this->resultKey('invalid-failure', 0), [
            'status' => 'failure',
            'sample_id' => 's1',
        ], 60);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Stored lazy parallel batch failure for index 0 is invalid');

        $store->failures('invalid-failure', 1, [0]);
    }

    public function test_indexed_reads_only_scan_requested_sample_indexes(): void
    {
        $store = $this->store();
        $store->start('indexed-read', 2, 60);
        $store->recordSuccess('indexed-read', 0, 's1', 'first output', 60);
        $this->cache->put($this->resultKey('indexed-read', 1), [
            'status' => 'success',
            'sample_id' => 's2',
            'actual_output' => ['corrupt'],
        ], 60);

        $this->assertSame([
            0 => ['sample_id' => 's1', 'actual_output' => 'first output'],
        ], $store->successfulResults('indexed-read', 2, [0]));
    }

    public function test_first_terminal_result_wins_for_duplicate_queue_delivery(): void
    {
        $store = $this->store();
        $store->start('duplicate-delivery', 1, 60);

        $store->recordSuccess('duplicate-delivery', 0, 's1', 'first output', 60);
        $store->recordFailure('duplicate-delivery', 0, 's1', 'later failure', 60);

        $this->assertSame([
            0 => ['sample_id' => 's1', 'actual_output' => 'first output'],
        ], $store->successfulResults('duplicate-delivery', 1));
        $this->assertSame([], $store->failures('duplicate-delivery', 1));
        $this->assertSame(['successes' => 1, 'failures' => 0], $store->progress('duplicate-delivery'));
    }

    public function test_finished_batches_keep_existing_successes_readable_until_ttl_expiry(): void
    {
        $store = $this->store();
        $store->start('closed-batch', 1, 60);
        $store->recordSuccess('closed-batch', 0, 's1', 'first output', 60);

        $store->finish('closed-batch', 1, 60);

        $this->assertSame([
            0 => ['sample_id' => 's1', 'actual_output' => 'first output'],
        ], $store->successfulResults('closed-batch', 1));
        $this->assertSame([], $store->failures('closed-batch', 1));
    }

    public function test_progress_reports_failures_after_batch_abort(): void
    {
        $store = $this->store();
        $store->start('aborted-progress', 2, 60);
        $store->recordFailure('aborted-progress', 0, 's1', 'runner exploded', 60);

        $store->abort('aborted-progress', 2, 60);

        $this->assertSame(['successes' => 0, 'failures' => 1], $store->progress('aborted-progress'));
    }

    public function test_progress_reads_metadata_and_compact_counters_only(): void
    {
        $cache = new GetRecordingCacheRepository($this->cache->getStore());
        $store = new CacheBatchResultStore($cache);

        $store->start('compact-progress', 2, 60);
        $store->recordSuccess('compact-progress', 0, 's1', 'first output', 60);
        $store->recordFailure('compact-progress', 1, 's2', 'runner exploded', 60);

        $cache->getKeys = [];

        $this->assertSame(['successes' => 1, 'failures' => 1], $store->progress('compact-progress'));
        $this->assertSame([
            $this->metaKey('compact-progress'),
            $this->progressSuccessKey('compact-progress'),
            $this->progressFailureKey('compact-progress'),
        ], $cache->getKeys);
    }

    public function test_progress_accepts_numeric_string_counters_from_cache_backends(): void
    {
        $store = $this->store();
        $store->start('numeric-string-progress', 2, 60);
        $this->cache->put($this->progressSuccessKey('numeric-string-progress'), '1', 60);
        $this->cache->put($this->progressFailureKey('numeric-string-progress'), '0', 60);

        $this->assertSame(['successes' => 1, 'failures' => 0], $store->progress('numeric-string-progress'));
    }

    public function test_terminal_result_status_must_be_known_before_progress_counter_updates(): void
    {
        $store = $this->store();
        $store->start('unknown-terminal-status', 1, 60);
        $method = new \ReflectionMethod($store, 'recordTerminalResult');
        $method->setAccessible(true);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("terminal result status 'skipped' is invalid");

        $method->invoke($store, 'unknown-terminal-status', 0, [
            'status' => 'skipped',
            'sample_id' => 's1',
            'error' => 'not run',
        ], 60);
    }

    public function test_result_write_rolls_back_when_progress_metadata_refresh_fails(): void
    {
        $cache = new ThrowingMetaRefreshCacheRepository($this->cache->getStore());
        $store = new CacheBatchResultStore($cache);

        $store->start('rollback-progress', 1, 60);
        $cache->throwOnMetaRefresh = true;

        try {
            $store->recordSuccess('rollback-progress', 0, 's1', 'first output', 120);
            $this->fail('Expected metadata refresh failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('meta refresh down', $e->getMessage());
        }

        $this->assertSame(['successes' => 0, 'failures' => 0], $store->progress('rollback-progress'));

        $cache->throwOnMetaRefresh = false;
        $store->recordSuccess('rollback-progress', 0, 's1', 'first output', 120);

        $this->assertSame(['successes' => 1, 'failures' => 0], $store->progress('rollback-progress'));
        $this->assertSame([
            0 => ['sample_id' => 's1', 'actual_output' => 'first output'],
        ], $store->successfulResults('rollback-progress', 1));
    }

    public function test_finish_marks_batch_closed_without_rescanning_sample_results(): void
    {
        $cache = new GetRecordingCacheRepository($this->cache->getStore());
        $store = new CacheBatchResultStore($cache);

        $store->start('finish-without-scan', 2, 60);
        $store->recordSuccess('finish-without-scan', 0, 's1', 'first output', 60);
        $store->recordSuccess('finish-without-scan', 1, 's2', 'second output', 60);

        $cache->getKeys = [];
        $store->finish('finish-without-scan', 2, 60);

        $this->assertSame([], $cache->getKeys);
        $this->assertSame([
            0 => ['sample_id' => 's1', 'actual_output' => 'first output'],
            1 => ['sample_id' => 's2', 'actual_output' => 'second output'],
        ], $store->successfulResults('finish-without-scan', 2));
    }

    public function test_finished_or_aborted_batches_ignore_late_job_writes(): void
    {
        $store = $this->store();

        $store->start('finished-batch', 1, 60);
        $store->finish('finished-batch', 1, 60);
        $store->recordSuccess('finished-batch', 0, 's1', 'late output', 60);

        $store->start('aborted-batch', 1, 60);
        $store->abort('aborted-batch', 1, 60);
        $store->recordFailure('aborted-batch', 0, 's1', 'late failure', 60);

        $this->assertSame([], $store->successfulResults('finished-batch', 1));
        $this->assertSame([], $store->failures('aborted-batch', 1));
    }

    public function test_finishing_batch_after_result_add_keeps_racing_success_readable(): void
    {
        $store = null;
        $cache = new ClosingAfterAddCacheRepository(
            store: $this->cache->getStore(),
            onAdd: static function () use (&$store): void {
                if (! $store instanceof CacheBatchResultStore) {
                    self::fail('Expected result store to be initialized before cache add.');
                }

                $store->finish('racing-batch', 1, 60);
            },
        );
        $store = new CacheBatchResultStore($cache);

        $store->start('racing-batch', 1, 60);
        $store->recordSuccess('racing-batch', 0, 's1', 'late output', 60);

        $this->assertSame([
            0 => ['sample_id' => 's1', 'actual_output' => 'late output'],
        ], $store->successfulResults('racing-batch', 1));
    }

    public function test_aborting_batch_after_result_add_removes_racing_job_write(): void
    {
        $store = null;
        $cache = new ClosingAfterAddCacheRepository(
            store: $this->cache->getStore(),
            onAdd: static function () use (&$store): void {
                if (! $store instanceof CacheBatchResultStore) {
                    self::fail('Expected result store to be initialized before cache add.');
                }

                $store->abort('aborting-batch', 1, 60);
            },
        );
        $store = new CacheBatchResultStore($cache);

        $store->start('aborting-batch', 1, 60);
        $store->recordSuccess('aborting-batch', 0, 's1', 'late output', 60);

        $this->assertSame([], $store->successfulResults('aborting-batch', 1));
    }

    public function test_recording_result_refreshes_active_metadata_ttl_to_match_newest_result(): void
    {
        $cache = new PutRecordingCacheRepository($this->cache->getStore());
        $store = new CacheBatchResultStore($cache);

        $store->start('refresh-meta', 1, 60);
        $cache->putRecords = [];

        $store->recordSuccess('refresh-meta', 0, 's1', 'first output', 120);

        $metaPuts = array_values(array_filter(
            $cache->putRecords,
            fn (array $record): bool => $record['key'] === $this->metaKey('refresh-meta'),
        ));

        $this->assertSame([
            [
                'key' => $this->metaKey('refresh-meta'),
                'value' => ['sample_count' => 1, 'status' => 'active', 'ttl_seconds' => 120],
                'ttl' => 120,
            ],
        ], $metaPuts);
    }

    public function test_no_lock_progress_fallback_does_not_extend_metadata_when_existing_counter_ttl_cannot_refresh(): void
    {
        $cache = new ThrowingLockPutRecordingCacheRepository($this->cache->getStore());
        $store = new CacheBatchResultStore($cache);

        $store->start('no-lock-refresh', 2, 60);
        $store->recordSuccess('no-lock-refresh', 0, 's1', 'first output', 60);
        $cache->putRecords = [];

        $store->recordSuccess('no-lock-refresh', 1, 's2', 'second output', 120);

        $metaPuts = array_values(array_filter(
            $cache->putRecords,
            fn (array $record): bool => $record['key'] === $this->metaKey('no-lock-refresh'),
        ));

        $this->assertSame([], $metaPuts);
        $this->assertSame(['successes' => 2, 'failures' => 0], $store->progress('no-lock-refresh'));
    }

    private function store(): CacheBatchResultStore
    {
        return new CacheBatchResultStore($this->cache);
    }

    private function resultKey(string $batchId, int $index): string
    {
        return sprintf('eval-harness:batch-results:%s:result:%d', $batchId, $index);
    }

    private function progressSuccessKey(string $batchId): string
    {
        return sprintf('eval-harness:batch-results:%s:progress:successes', $batchId);
    }

    private function progressFailureKey(string $batchId): string
    {
        return sprintf('eval-harness:batch-results:%s:progress:failures', $batchId);
    }

    private function metaKey(string $batchId): string
    {
        return sprintf('eval-harness:batch-results:%s:meta', $batchId);
    }
}

final class ClosingAfterAddCacheRepository extends IlluminateCacheRepository
{
    public function __construct(Store $store, private readonly Closure $onAdd)
    {
        parent::__construct($store);
    }

    public function add($key, $value, $ttl = null)
    {
        $added = parent::add($key, $value, $ttl);
        ($this->onAdd)();

        return $added;
    }
}

final class GetRecordingCacheRepository extends IlluminateCacheRepository
{
    /** @var list<mixed> */
    public array $getKeys = [];

    public function get($key, $default = null): mixed
    {
        $this->getKeys[] = $key;

        return parent::get($key, $default);
    }
}

class PutRecordingCacheRepository extends IlluminateCacheRepository
{
    /** @var list<array{key: mixed, value: mixed, ttl: mixed}> */
    public array $putRecords = [];

    public function put($key, $value, $ttl = null): bool
    {
        $this->putRecords[] = ['key' => $key, 'value' => $value, 'ttl' => $ttl];

        return parent::put($key, $value, $ttl);
    }

    public function lock($name, $seconds = 0, $owner = null): BlockingCallbackLock
    {
        return new BlockingCallbackLock;
    }
}

final class ThrowingLockPutRecordingCacheRepository extends PutRecordingCacheRepository
{
    public function lock($name, $seconds = 0, $owner = null): never
    {
        throw new \RuntimeException('lock unavailable');
    }
}

final class ThrowingMetaRefreshCacheRepository extends PutRecordingCacheRepository
{
    public bool $throwOnMetaRefresh = false;

    public function put($key, $value, $ttl = null): bool
    {
        if (
            $this->throwOnMetaRefresh
            && is_string($key)
            && str_ends_with($key, ':meta')
            && is_array($value)
            && ($value['ttl_seconds'] ?? null) === 120
        ) {
            throw new \RuntimeException('meta refresh down');
        }

        return parent::put($key, $value, $ttl);
    }
}

final class BlockingCallbackLock
{
    public function block($seconds, ?callable $callback = null): mixed
    {
        return $callback === null ? true : $callback();
    }
}
