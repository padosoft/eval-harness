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

    public function test_closing_batch_after_result_add_removes_racing_job_write(): void
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

        $this->assertSame([], $store->successfulResults('racing-batch', 1));
    }

    private function store(): CacheBatchResultStore
    {
        return new CacheBatchResultStore($this->cache);
    }

    private function resultKey(string $batchId, int $index): string
    {
        return sprintf('eval-harness:batch-results:%s:result:%d', $batchId, $index);
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
