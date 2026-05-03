<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit;

use Padosoft\EvalHarness\Batches\SerialBatch;
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

    public function test_yaml_loader_is_bound(): void
    {
        $this->assertInstanceOf(YamlDatasetLoader::class, $this->app->make(YamlDatasetLoader::class));
    }

    public function test_serial_batch_is_bound(): void
    {
        $this->assertInstanceOf(SerialBatch::class, $this->app->make(SerialBatch::class));
    }

    public function test_config_is_merged(): void
    {
        $endpoint = config('eval-harness.metrics.cosine_embedding.endpoint');
        $this->assertIsString($endpoint);
        $this->assertNotEmpty($endpoint);
    }
}
