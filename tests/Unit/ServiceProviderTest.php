<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Padosoft\EvalHarness\Adversarial\AdversarialRegressionGate;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifestStore;
use Padosoft\EvalHarness\Batches\BatchProfile;
use Padosoft\EvalHarness\Batches\BatchProfileResolver;
use Padosoft\EvalHarness\Batches\BatchProgressReporter;
use Padosoft\EvalHarness\Batches\BatchResultStore;
use Padosoft\EvalHarness\Batches\BatchTerminalProgressReporter;
use Padosoft\EvalHarness\Batches\LazyParallelBatch;
use Padosoft\EvalHarness\Batches\NullBatchProgressReporter;
use Padosoft\EvalHarness\Batches\SerialBatch;
use Padosoft\EvalHarness\Contracts\EmbeddingClient;
use Padosoft\EvalHarness\Contracts\JudgeClient;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\EvalHarnessServiceProvider;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\ReportApi\ReportArtifactRepository;
use Padosoft\EvalHarness\Reports\FailedSampleDatasetExporter;
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

    public function test_judge_client_is_bound(): void
    {
        $this->assertInstanceOf(JudgeClient::class, $this->app->make(JudgeClient::class));
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

    public function test_adversarial_run_manifest_store_is_bound(): void
    {
        $this->assertInstanceOf(AdversarialRunManifestStore::class, $this->app->make(AdversarialRunManifestStore::class));
    }

    public function test_adversarial_regression_gate_is_an_explicit_singleton(): void
    {
        $this->assertTrue($this->app->bound(AdversarialRegressionGate::class));

        $first = $this->app->make(AdversarialRegressionGate::class);
        $second = $this->app->make(AdversarialRegressionGate::class);

        $this->assertInstanceOf(AdversarialRegressionGate::class, $first);
        $this->assertSame($first, $second, 'AdversarialRegressionGate must be a container singleton so gate policy stays stable across command/store resolution.');
    }

    public function test_failed_sample_dataset_exporter_is_an_explicit_singleton(): void
    {
        $this->assertTrue($this->app->bound(FailedSampleDatasetExporter::class));

        $first = $this->app->make(FailedSampleDatasetExporter::class);
        $second = $this->app->make(FailedSampleDatasetExporter::class);

        $this->assertInstanceOf(FailedSampleDatasetExporter::class, $first);
        $this->assertSame($first, $second, 'FailedSampleDatasetExporter must be an explicit singleton binding, not only an auto-resolvable concrete class.');
    }

    public function test_report_artifact_repository_is_an_explicit_singleton(): void
    {
        $this->assertTrue($this->app->bound(ReportArtifactRepository::class));

        $first = $this->app->make(ReportArtifactRepository::class);
        $second = $this->app->make(ReportArtifactRepository::class);

        $this->assertInstanceOf(ReportArtifactRepository::class, $first);
        $this->assertSame($first, $second, 'ReportArtifactRepository must be an explicit singleton binding for the report API.');
    }

    public function test_config_is_merged(): void
    {
        $endpoint = config('eval-harness.metrics.cosine_embedding.endpoint');
        $this->assertIsString($endpoint);
        $this->assertNotEmpty($endpoint);
        $this->assertFalse(config('eval-harness.runtime.raise_exceptions'));
        $this->assertSame(0, config('eval-harness.runtime.provider_retry_attempts'));
        $this->assertSame(100, config('eval-harness.runtime.provider_retry_sleep_milliseconds'));
        $this->assertSame(3600, config('eval-harness.batches.lazy_parallel.result_ttl_seconds'));
        $this->assertSame(60, config('eval-harness.batches.lazy_parallel.wait_timeout_seconds'));
        $this->assertFalse(config('eval-harness.api.enabled'));
        $this->assertSame('eval-harness/api', config('eval-harness.api.prefix'));
        $this->assertSame([], config('eval-harness.api.middleware'));
    }

    public function test_api_route_middleware_normalizes_associative_arrays(): void
    {
        config([
            'eval-harness.api.middleware' => [
                'web' => 'web',
                'auth' => 'auth.admin',
                'blank' => ' ',
            ],
        ]);

        $provider = new EvalHarnessServiceProvider($this->app);
        $method = new \ReflectionMethod($provider, 'apiRouteMiddleware');
        $method->setAccessible(true);

        $this->assertSame(['web', 'auth.admin'], $method->invoke($provider, $this->app['config']));
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

    public function test_batch_profile_resolver_is_an_explicit_singleton_with_built_in_profiles(): void
    {
        $this->assertTrue($this->app->bound(BatchProfileResolver::class));

        $first = $this->app->make(BatchProfileResolver::class);
        $second = $this->app->make(BatchProfileResolver::class);

        $this->assertInstanceOf(BatchProfileResolver::class, $first);
        $this->assertSame($first, $second, 'BatchProfileResolver must be a container singleton.');
        $this->assertContains(BatchProfile::NAME_CI, $first->names());
        $this->assertContains(BatchProfile::NAME_SMOKE, $first->names());
        $this->assertContains(BatchProfile::NAME_NIGHTLY, $first->names());
    }

    public function test_batch_profile_resolver_picks_up_config_overrides(): void
    {
        config(['eval-harness.batches.profiles' => [
            'ci' => ['concurrency' => 8, 'rate_limit' => 30],
        ]]);
        $this->app->forgetInstance(BatchProfileResolver::class);

        $profile = $this->app->make(BatchProfileResolver::class)->resolve(BatchProfile::NAME_CI);

        $this->assertSame(8, $profile->concurrency);
        $this->assertSame(30, $profile->rateLimit);
    }

    public function test_default_batch_progress_reporter_is_no_op(): void
    {
        $reporter = $this->app->make(BatchProgressReporter::class);

        $this->assertInstanceOf(NullBatchProgressReporter::class, $reporter);
    }

    public function test_lazy_parallel_batch_uses_bound_progress_reporter(): void
    {
        $custom = new class implements BatchProgressReporter
        {
            public function reportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void
            {
                //
            }
        };
        $this->app->instance(BatchProgressReporter::class, $custom);
        $this->app->forgetInstance(LazyParallelBatch::class);

        $batch = $this->app->make(LazyParallelBatch::class);

        $reporterProperty = new \ReflectionProperty($batch, 'progressReporter');

        $this->assertSame($custom, $reporterProperty->getValue($batch));
    }

    public function test_lazy_parallel_batch_prefers_terminal_progress_reporter_binding(): void
    {
        // Host apps that implement the optional sub-contract should
        // be able to bind under either key. The provider must prefer
        // the BatchTerminalProgressReporter binding when present so
        // the terminal-status signal actually reaches LazyParallelBatch.
        $custom = new class implements BatchTerminalProgressReporter
        {
            public function reportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void
            {
                //
            }

            public function reportTerminal(string $batchId, int $samplesCompleted, int $totalSamples, string $status): void
            {
                //
            }
        };
        $this->app->instance(BatchTerminalProgressReporter::class, $custom);
        $this->app->forgetInstance(LazyParallelBatch::class);

        $batch = $this->app->make(LazyParallelBatch::class);
        $reporterProperty = new \ReflectionProperty($batch, 'progressReporter');

        $this->assertSame($custom, $reporterProperty->getValue($batch));
    }

    public function test_lazy_parallel_batch_uses_aliased_reporter_under_factory_terminal_binding(): void
    {
        // Round-38 fix: when a host app binds the terminal reporter
        // via `bind()` (factory, not singleton), the LazyParallelBatch
        // factory must route through `BatchProgressReporter::class`
        // (which the singletonIf alias resolves to the terminal
        // binding) instead of resolving the terminal key directly.
        // Otherwise LazyParallelBatch gets a DIFFERENT instance from
        // any other consumer type-hinting the parent interface,
        // breaking the "bind under either key" contract.
        $instances = [];
        $this->app->bind(BatchTerminalProgressReporter::class, function () use (&$instances) {
            $reporter = new class implements BatchTerminalProgressReporter
            {
                public function reportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void
                {
                    //
                }

                public function reportTerminal(string $batchId, int $samplesCompleted, int $totalSamples, string $status): void
                {
                    //
                }
            };
            $instances[] = $reporter;

            return $reporter;
        });
        $this->app->forgetInstance(BatchProgressReporter::class);
        $this->app->forgetInstance(LazyParallelBatch::class);

        $batch = $this->app->make(LazyParallelBatch::class);
        $reporterFromBatch = (new \ReflectionProperty($batch, 'progressReporter'))->getValue($batch);
        $reporterFromContainer = $this->app->make(BatchProgressReporter::class);

        // Both consumers must see the same instance — the singletonIf
        // alias caches the first resolution so subsequent
        // `BatchProgressReporter` makes return that same object even
        // though the underlying `bind()` factory would otherwise
        // produce fresh instances.
        $this->assertSame($reporterFromBatch, $reporterFromContainer);
    }

    public function test_parent_progress_reporter_resolves_to_terminal_binding_when_only_terminal_is_bound(): void
    {
        // Round-35 fix: the "bind under either key" contract only
        // worked inside the LazyParallelBatch factory. Any consumer
        // type-hinting the parent BatchProgressReporter interface
        // (host-app code, tests, downstream services) would silently
        // resolve to the package's NullBatchProgressReporter even
        // when the host app had bound a real reporter under the
        // sub-contract. This test pins the bidirectional alias:
        // when ONLY BatchTerminalProgressReporter is bound,
        // BatchProgressReporter resolution returns the same
        // instance.
        $custom = new class implements BatchTerminalProgressReporter
        {
            public function reportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void
            {
                //
            }

            public function reportTerminal(string $batchId, int $samplesCompleted, int $totalSamples, string $status): void
            {
                //
            }
        };
        $this->app->instance(BatchTerminalProgressReporter::class, $custom);
        $this->app->forgetInstance(BatchProgressReporter::class);

        $resolved = $this->app->make(BatchProgressReporter::class);

        $this->assertSame($custom, $resolved);
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
