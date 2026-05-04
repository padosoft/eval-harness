<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Padosoft\EvalHarness\Batches\BatchResultStore;
use Padosoft\EvalHarness\Batches\CacheBatchResultStore;
use Padosoft\EvalHarness\Batches\LazyParallelBatch;
use Padosoft\EvalHarness\Batches\SerialBatch;
use Padosoft\EvalHarness\Console\EvalCommand;
use Padosoft\EvalHarness\Contracts\EmbeddingClient;
use Padosoft\EvalHarness\Contracts\JudgeClient;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\Embeddings\OpenAiCompatibleEmbeddingClient;
use Padosoft\EvalHarness\Judges\OpenAiCompatibleJudgeClient;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Outputs\SavedOutputsLoader;
use Padosoft\EvalHarness\Support\TimeoutNormalizer;

/**
 * Package service provider.
 *
 * Responsibilities:
 *   - Merge the package config under `eval-harness.*`.
 *   - Bind the {@see EvalEngine} as a singleton so dataset
 *     registrations survive across the same request lifecycle.
 *   - Register the `eval-harness:run` Artisan command in the
 *     console kernel.
 *   - Publish the config when the operator runs
 *     `php artisan vendor:publish --tag=eval-harness-config`.
 *
 * The provider is intentionally NOT marked `final` so test
 * doubles + downstream extensions can subclass when they need
 * to swap a metric resolver or the YAML loader. The package
 * itself never relies on subclassing.
 */
class EvalHarnessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/eval-harness.php',
            'eval-harness',
        );

        $this->app->singleton(MetricResolver::class, static function (Container $app): MetricResolver {
            return new MetricResolver($app);
        });

        $this->app->singleton(EmbeddingClient::class, static function (Container $app): EmbeddingClient {
            return new OpenAiCompatibleEmbeddingClient(
                http: $app->make(Factory::class),
                config: $app->make(ConfigRepository::class),
            );
        });

        $this->app->singleton(JudgeClient::class, static function (Container $app): JudgeClient {
            return new OpenAiCompatibleJudgeClient(
                http: $app->make(Factory::class),
                config: $app->make(ConfigRepository::class),
            );
        });

        $this->app->singleton(YamlDatasetLoader::class, static function (): YamlDatasetLoader {
            return new YamlDatasetLoader;
        });

        $this->app->singleton(SavedOutputsLoader::class, static function (): SavedOutputsLoader {
            return new SavedOutputsLoader;
        });

        $this->app->singleton(SerialBatch::class, static function (): SerialBatch {
            return new SerialBatch;
        });

        $this->app->singleton(BatchResultStore::class, static function (Container $app): BatchResultStore {
            /** @var CacheFactory $cache */
            $cache = $app->make(CacheFactory::class);
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);
            $cacheStore = $config->get('eval-harness.batches.lazy_parallel.cache_store');
            $cacheStore = is_string($cacheStore) ? trim($cacheStore) : null;

            return new CacheBatchResultStore(
                $cache->store($cacheStore !== '' ? $cacheStore : null),
            );
        });

        $this->app->singleton(LazyParallelBatch::class, static function (Container $app): LazyParallelBatch {
            /** @var ConfigRepository $config */
            $config = $app->make(ConfigRepository::class);

            return new LazyParallelBatch(
                dispatcher: $app->make(Dispatcher::class),
                resultStore: $app->make(BatchResultStore::class),
                container: $app,
                resultTtlSeconds: TimeoutNormalizer::normalize(
                    $config->get('eval-harness.batches.lazy_parallel.result_ttl_seconds'),
                    3600,
                ),
                defaultWaitTimeoutSeconds: TimeoutNormalizer::normalize(
                    $config->get('eval-harness.batches.lazy_parallel.wait_timeout_seconds'),
                    60,
                ),
            );
        });

        $this->app->singleton(EvalEngine::class, static function (Container $app): EvalEngine {
            return new EvalEngine(
                container: $app,
                metricResolver: $app->make(MetricResolver::class),
                yamlLoader: $app->make(YamlDatasetLoader::class),
                serialBatch: $app->make(SerialBatch::class),
                config: $app->make(ConfigRepository::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([EvalCommand::class]);

            $this->publishes([
                __DIR__.'/../config/eval-harness.php' => $this->configPath('eval-harness.php'),
            ], 'eval-harness-config');
        }
    }

    private function configPath(string $file): string
    {
        // Mirrors Laravel's config_path() helper without depending on
        // the global helper being bootstrapped (some Testbench setups
        // run register() before the helper file is required).
        $base = $this->app->basePath('config');

        return $base.DIRECTORY_SEPARATOR.$file;
    }
}
