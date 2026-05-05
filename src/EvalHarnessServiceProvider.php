<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use Padosoft\EvalHarness\Adversarial\AdversarialDatasetFactory;
use Padosoft\EvalHarness\Adversarial\AdversarialRegressionGate;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifestStore;
use Padosoft\EvalHarness\Batches\BatchProfileResolver;
use Padosoft\EvalHarness\Batches\BatchProgressReporter;
use Padosoft\EvalHarness\Batches\BatchResultStore;
use Padosoft\EvalHarness\Batches\BatchTerminalProgressReporter;
use Padosoft\EvalHarness\Batches\CacheBatchResultStore;
use Padosoft\EvalHarness\Batches\LazyParallelBatch;
use Padosoft\EvalHarness\Batches\NullBatchProgressReporter;
use Padosoft\EvalHarness\Batches\SerialBatch;
use Padosoft\EvalHarness\Console\AdversarialCommand;
use Padosoft\EvalHarness\Console\EvalCommand;
use Padosoft\EvalHarness\Contracts\EmbeddingClient;
use Padosoft\EvalHarness\Contracts\JudgeClient;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\Embeddings\OpenAiCompatibleEmbeddingClient;
use Padosoft\EvalHarness\Judges\OpenAiCompatibleJudgeClient;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Outputs\SavedOutputsLoader;
use Padosoft\EvalHarness\ReportApi\ReportArtifactRepository;
use Padosoft\EvalHarness\Reports\FailedSampleDatasetExporter;
use Padosoft\EvalHarness\Support\RuntimeOptions;
use Padosoft\EvalHarness\Support\TimeoutNormalizer;

/**
 * Package service provider.
 *
 * Responsibilities:
 *   - Merge the package config under `eval-harness.*`.
 *   - Bind the {@see EvalEngine} as a singleton so dataset
 *     registrations survive across the same request lifecycle.
 *   - Register the package Artisan commands in the
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

        $this->app->singleton(FailedSampleDatasetExporter::class, static function (): FailedSampleDatasetExporter {
            return new FailedSampleDatasetExporter;
        });

        $this->app->singleton(ReportArtifactRepository::class, static function (Container $app): ReportArtifactRepository {
            return new ReportArtifactRepository(
                filesystems: $app->make(\Illuminate\Contracts\Filesystem\Factory::class),
                config: $app->make(ConfigRepository::class),
            );
        });

        $this->app->singleton(AdversarialDatasetFactory::class, static function (Container $app): AdversarialDatasetFactory {
            return new AdversarialDatasetFactory($app->make(MetricResolver::class));
        });

        $this->app->singleton(AdversarialRunManifestStore::class, static function (): AdversarialRunManifestStore {
            return new AdversarialRunManifestStore;
        });

        $this->app->singleton(AdversarialRegressionGate::class, static function (): AdversarialRegressionGate {
            return new AdversarialRegressionGate;
        });

        $this->app->singleton(SerialBatch::class, static function (): SerialBatch {
            return new SerialBatch;
        });

        $this->app->singleton(BatchProfileResolver::class, static function (Container $app): BatchProfileResolver {
            return new BatchProfileResolver($app->make(ConfigRepository::class));
        });

        // Host apps may bind their reporter under either
        // `BatchProgressReporter::class` (the parent interface) or
        // `BatchTerminalProgressReporter::class` (the optional
        // status-aware sub-contract). Documented contract: terminal
        // sub-contract WINS when both keys are bound.
        //
        // Implementation:
        //   1. `singletonIf(parent, NullReporter)` — installs the
        //      package's fallback only when the host hasn't bound
        //      the parent (preserves host's parent-only bindings).
        //   2. `extend(parent, terminal-substitutor)` — runs at
        //      first parent resolution; substitutes the terminal
        //      binding when present.
        //
        // Constraints:
        //   - Bindings must be registered in `register()` (the
        //     normal Laravel pattern). The first parent resolution
        //     caches the singleton via `extend()`; later terminal
        //     bindings won't override an already-resolved instance.
        //   - The recursion guard handles the rare case where a
        //     host app aliases the terminal contract back to the
        //     parent (e.g.
        //     `bind(Terminal::class, fn ($app) =>
        //     $app->make(Parent::class))`). On recursion the
        //     extender returns the existing reporter without
        //     infinite resolution.
        $this->app->singletonIf(BatchProgressReporter::class, static function (): BatchProgressReporter {
            return new NullBatchProgressReporter;
        });
        $resolvingTerminalSubstitution = false;
        $this->app->extend(BatchProgressReporter::class, static function (BatchProgressReporter $existing, Container $app) use (&$resolvingTerminalSubstitution): BatchProgressReporter {
            if ($resolvingTerminalSubstitution) {
                return $existing;
            }
            if (! $app->bound(BatchTerminalProgressReporter::class)) {
                return $existing;
            }
            $resolvingTerminalSubstitution = true;
            try {
                return $app->make(BatchTerminalProgressReporter::class);
            } finally {
                $resolvingTerminalSubstitution = false;
            }
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
                // Route through `BatchProgressReporter::class` only.
                // The parent-interface singletonIf above resolves to
                // the host app's `BatchTerminalProgressReporter`
                // binding when present, so the factory does NOT
                // resolve the terminal key directly. Without this,
                // a host app binding the terminal reporter via
                // `bind()` (factory, not singleton) would yield a
                // DIFFERENT instance to LazyParallelBatch than to
                // any other consumer type-hinting the parent
                // interface — breaking the advertised "bind under
                // either key" contract.
                progressReporter: $app->make(BatchProgressReporter::class),
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
            $this->commands([EvalCommand::class, AdversarialCommand::class]);

            $this->publishes([
                __DIR__.'/../config/eval-harness.php' => $this->configPath('eval-harness.php'),
            ], 'eval-harness-config');
        }

        $this->registerReportApiRoutes();
    }

    private function registerReportApiRoutes(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);
        if (! RuntimeOptions::normalizeBoolean($config->get('eval-harness.api.enabled'), false)) {
            return;
        }

        if (method_exists($this->app, 'routesAreCached') && $this->app->routesAreCached()) {
            return;
        }

        if (! $this->app->bound(Registrar::class)) {
            return;
        }

        $router = $this->app->make(Registrar::class);
        $registerRoutes = require __DIR__.'/../routes/eval-harness-api.php';
        $registerRoutes(
            $router,
            $this->apiRoutePrefix($config),
            $this->apiRouteMiddleware($config),
        );
    }

    private function apiRoutePrefix(ConfigRepository $config): string
    {
        $prefix = $config->get('eval-harness.api.prefix', 'eval-harness/api');
        if (! is_string($prefix) || trim($prefix) === '') {
            return 'eval-harness/api';
        }

        $prefix = trim(trim($prefix), '/');

        return $prefix === '' ? 'eval-harness/api' : $prefix;
    }

    /**
     * @return list<string>
     */
    private function apiRouteMiddleware(ConfigRepository $config): array
    {
        $middleware = $config->get('eval-harness.api.middleware', []);
        if (is_string($middleware)) {
            $middleware = array_map('trim', explode(',', $middleware));
        }

        if (! is_array($middleware)) {
            return [];
        }

        $normalized = [];
        foreach (array_values($middleware) as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $normalized[] = trim($entry);
            }
        }

        return $normalized;
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
