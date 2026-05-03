<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Padosoft\EvalHarness\Batches\BatchResultStore;
use Padosoft\EvalHarness\Batches\LazyParallelBatch;
use Padosoft\EvalHarness\Batches\SerialBatch;
use Padosoft\EvalHarness\Contracts\EmbeddingClient;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\EvalHarnessServiceProvider;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_provider_is_loaded_via_auto_discovery(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(EvalHarnessServiceProvider::class),
            'Service provider must be registered for downstream consumers.',
        );
    }

    public function test_eval_engine_is_a_singleton(): void
    {
        $first = $this->app->make(EvalEngine::class);
        $second = $this->app->make(EvalEngine::class);

        $this->assertSame($first, $second, 'EvalEngine must be a container singleton so dataset registrations persist.');
    }

    public function test_metric_resolver_is_bound(): void
    {
        $this->assertInstanceOf(MetricResolver::class, $this->app->make(MetricResolver::class));
    }

    public function test_embedding_client_is_bound(): void
    {
        $this->assertInstanceOf(EmbeddingClient::class, $this->app->make(EmbeddingClient::class));
    }

    public function test_yaml_loader_is_bound(): void
    {
        $this->assertInstanceOf(YamlDatasetLoader::class, $this->app->make(YamlDatasetLoader::class));
    }

    public function test_serial_batch_is_bound(): void
    {
        $this->assertInstanceOf(SerialBatch::class, $this->app->make(SerialBatch::class));
    }

    public function test_lazy_parallel_batch_is_bound(): void
    {
        $this->assertInstanceOf(LazyParallelBatch::class, $this->app->make(LazyParallelBatch::class));
    }

    public function test_batch_result_store_is_bound(): void
    {
        $this->assertInstanceOf(BatchResultStore::class, $this->app->make(BatchResultStore::class));
    }

    public function test_config_is_merged(): void
    {
        $endpoint = config('eval-harness.metrics.cosine_embedding.endpoint');
        $this->assertIsString($endpoint);
        $this->assertNotEmpty($endpoint);
        $this->assertSame(3600, config('eval-harness.batches.lazy_parallel.result_ttl_seconds'));
        $this->assertSame(60, config('eval-harness.batches.lazy_parallel.wait_timeout_seconds'));
    }

    public function test_lazy_parallel_batch_uses_configured_ttl_and_wait_timeout(): void
    {
        config([
            'eval-harness.batches.lazy_parallel.result_ttl_seconds' => 7200,
            'eval-harness.batches.lazy_parallel.wait_timeout_seconds' => 120,
        ]);
        $this->app->forgetInstance(LazyParallelBatch::class);

        $batch = $this->app->make(LazyParallelBatch::class);

        $ttl = new \ReflectionProperty($batch, 'resultTtlSeconds');
        $wait = new \ReflectionProperty($batch, 'defaultWaitTimeoutSeconds');

        $this->assertSame(7200, $ttl->getValue($batch));
        $this->assertSame(120, $wait->getValue($batch));
    }

    public function test_lazy_parallel_batch_normalizes_invalid_ttl_and_wait_timeout_config(): void
    {
        config([
            'eval-harness.batches.lazy_parallel.result_ttl_seconds' => '',
            'eval-harness.batches.lazy_parallel.wait_timeout_seconds' => 'not-a-number',
        ]);
        $this->app->forgetInstance(LazyParallelBatch::class);

        $batch = $this->app->make(LazyParallelBatch::class);

        $ttl = new \ReflectionProperty($batch, 'resultTtlSeconds');
        $wait = new \ReflectionProperty($batch, 'defaultWaitTimeoutSeconds');

        $this->assertSame(3600, $ttl->getValue($batch));
        $this->assertSame(60, $wait->getValue($batch));
    }

    public function test_batch_result_store_uses_configured_cache_store(): void
    {
        /** @var CacheFactory $cacheFactory */
        $cacheFactory = $this->app->make(CacheFactory::class);
        $recordingFactory = new RecordingCacheFactory($cacheFactory);
        $this->app->instance(CacheFactory::class, $recordingFactory);
        config(['eval-harness.batches.lazy_parallel.cache_store' => ' eval-results ']);
        $this->app->forgetInstance(BatchResultStore::class);

        $this->app->make(BatchResultStore::class);

        $this->assertSame(['eval-results'], $recordingFactory->requestedStores);
    }
}

final class RecordingCacheFactory implements CacheFactory
{
    /** @var list<string|null> */
    public array $requestedStores = [];

    public function __construct(
        private readonly CacheFactory $cache,
    ) {}

    public function store($name = null): CacheRepository
    {
        $this->requestedStores[] = is_string($name) ? $name : null;

        return $this->cache->store();
    }
}
