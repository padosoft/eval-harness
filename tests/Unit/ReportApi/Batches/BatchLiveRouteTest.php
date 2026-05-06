<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi\Batches;

use Padosoft\EvalHarness\Batches\BatchLiveRegistry;
use Padosoft\EvalHarness\Batches\CacheBatchResultStore;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\Tests\TestCase;

final class BatchLiveRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.api.enabled', true);
        $app['config']->set('eval-harness.api.prefix', 'eval-harness/api');
        $app['config']->set('eval-harness.api.middleware', []);
        $app['config']->set('eval-harness.batches.lazy_parallel.cache_store', 'array');
    }

    public function test_live_batches_returns_schema_and_registered_batches(): void
    {
        $store = $this->app->make(CacheBatchResultStore::class);
        $store->start('batch-route-live', 2, 60);
        $this->app->make(BatchLiveRegistry::class)->register('batch-route-live', 60);

        $this->getJson('/eval-harness/api/batches/live')
            ->assertOk()
            ->assertJsonPath('schema_version', ReportApiSchema::VERSION)
            ->assertJsonPath('schema', ReportApiSchema::SCHEMA_BATCHES_LIVE)
            ->assertJsonPath('data.batches.0.id', 'batch-route-live');
    }

    public function test_batch_progress_returns_counts(): void
    {
        $store = $this->app->make(CacheBatchResultStore::class);
        $store->start('batch-progress', 3, 60);
        $store->recordSuccess('batch-progress', 0, 's1', 'one', 60);
        $store->recordFailure('batch-progress', 1, 's2', 'boom', 60);

        $this->getJson('/eval-harness/api/batches/batch-progress/progress')
            ->assertOk()
            ->assertJsonPath('schema', ReportApiSchema::SCHEMA_BATCH_PROGRESS)
            ->assertJsonPath('data.id', 'batch-progress')
            ->assertJsonPath('data.sample_count', 3)
            ->assertJsonPath('data.successes', 1)
            ->assertJsonPath('data.failures', 1)
            ->assertJsonPath('data.pending', 1);
    }

    public function test_batch_progress_returns_not_found_when_metadata_missing(): void
    {
        $this->getJson('/eval-harness/api/batches/missing/progress')
            ->assertNotFound();
    }
}
