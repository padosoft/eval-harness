<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Batches;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Padosoft\EvalHarness\Batches\BatchLiveRegistry;
use Padosoft\EvalHarness\Batches\CacheBatchResultStore;
use Padosoft\EvalHarness\Tests\TestCase;

final class BatchLiveRegistryTest extends TestCase
{
    private const LIVE_KEY = 'eval-harness:batches:live';

    private CacheRepository $cache;

    private CacheBatchResultStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var CacheFactory $cacheFactory */
        $cacheFactory = $this->app->make(CacheFactory::class);
        $this->cache = $cacheFactory->store('array');
        $this->store = new CacheBatchResultStore($this->cache);
    }

    public function test_registers_and_deregisters_live_batch(): void
    {
        $this->store->start('batch-live', 2, 60);
        $registry = new BatchLiveRegistry($this->cache, $this->store);

        $registry->register('batch-live', 60);

        $this->assertArrayHasKey('batch-live', $registry->live());

        $registry->deregister('batch-live');

        $this->assertSame([], $registry->live());
    }

    public function test_later_shorter_registration_keeps_existing_longer_entry(): void
    {
        $this->store->start('long-batch', 1, 3600);
        $this->store->start('short-batch', 1, 60);
        $registry = new BatchLiveRegistry($this->cache, $this->store);

        $registry->register('long-batch', 3600);
        $registry->register('short-batch', 60);

        $this->assertSame(['long-batch', 'short-batch'], array_keys($registry->live()));
    }

    public function test_live_read_prunes_expired_entries_and_missing_result_metadata(): void
    {
        $this->store->start('still-live', 1, 60);
        $this->cache->put(self::LIVE_KEY, [
            'expired' => time() - 5,
            'missing-meta' => time() + 60,
            'still-live' => time() + 60,
        ], 60);

        $registry = new BatchLiveRegistry($this->cache, $this->store);

        $this->assertSame(['still-live'], array_keys($registry->live()));
    }

    public function test_live_read_prunes_entries_with_malformed_result_metadata(): void
    {
        $this->store->start('still-live', 1, 60);
        $this->cache->put('eval-harness:batch-results:malformed-meta:meta', [
            'sample_count' => 'not-an-int',
            'status' => 'active',
            'ttl_seconds' => 60,
        ], 60);
        $this->cache->put(self::LIVE_KEY, [
            'malformed-meta' => time() + 60,
            'still-live' => time() + 60,
        ], 60);

        $registry = new BatchLiveRegistry($this->cache, $this->store);

        $this->assertSame(['still-live'], array_keys($registry->live()));
    }

    public function test_disabled_registry_is_noop(): void
    {
        $this->store->start('disabled', 1, 60);
        $registry = new BatchLiveRegistry($this->cache, $this->store, enabled: false);

        $registry->register('disabled', 60);

        $this->assertSame([], $registry->live());
    }
}
