<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Illuminate\Contracts\Container\Container;
use Padosoft\EvalHarness\Datasets\DatasetBuilder;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Resolves the strings / classes / instances declared by callers
 * via {@see DatasetBuilder::withMetrics()}
 * into concrete {@see Metric} instances.
 *
 * Four input shapes are supported:
 *   1. Metric instance — returned verbatim. Used when the caller
 *      needs custom constructor wiring.
 *   2. FQCN string — resolved through the container so constructor
 *      dependencies (HTTP client, config) are auto-wired.
 *   3. Built-in alias string ('exact-match', 'cosine-embedding',
 *      'llm-as-judge') — looked up against the static map below
 *      and then resolved through the container.
 *   4. Container alias / abstract — any string the container can
 *      `make()` (e.g. an `$app->bind('my-metric', MyMetric::class)`
 *      registered by a downstream package). Bound entries are
 *      preferred over both the alias map and class autoloading,
 *      so consumers can override built-ins by binding their own
 *      implementation under the alias.
 *
 * Mirrors R23 (pluggable pipeline registry): every concrete class
 * is asserted to implement the {@see Metric} interface so a typo'd
 * FQCN fails with a clear error instead of producing a runtime
 * "method does not exist" downstream.
 */
final class MetricResolver
{
    /**
     * Built-in metric aliases. Downstream packages can extend this
     * by binding their own Metric in the container under either an
     * alias or an FQCN.
     *
     * @var array<string, class-string<Metric>>
     */
    private const ALIASES = [
        'exact-match' => ExactMatchMetric::class,
        'cosine-embedding' => CosineEmbeddingMetric::class,
        'llm-as-judge' => LlmAsJudgeMetric::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function resolve(mixed $spec): Metric
    {
        if ($spec instanceof Metric) {
            return $spec;
        }

        if (! is_string($spec) || $spec === '') {
            throw new MetricException(
                sprintf(
                    'Metric spec must be a Metric instance, FQCN, or alias string; got %s.',
                    get_debug_type($spec),
                ),
            );
        }

        // Resolution order:
        //   1. Container binding under the spec verbatim (lets
        //      consumers register `$app->bind('my-metric', ...)`
        //      and have it picked up before alias / class lookup).
        //   2. Built-in alias map.
        //   3. FQCN string the autoloader can resolve.
        $abstract = self::ALIASES[$spec] ?? $spec;
        $isContainerBound = $this->container->bound($spec);

        if (! $isContainerBound && ! $this->container->bound($abstract) && ! class_exists($abstract)) {
            throw new MetricException(
                sprintf(
                    "Metric '%s' resolves to class '%s' which does not exist and is not bound in the container.",
                    $spec,
                    $abstract,
                ),
            );
        }

        $instance = $isContainerBound
            ? $this->container->make($spec)
            : $this->container->make($abstract);

        if (! $instance instanceof Metric) {
            throw new MetricException(
                sprintf(
                    "Metric '%s' resolves to '%s' which does not implement %s.",
                    $spec,
                    is_object($instance) ? $instance::class : get_debug_type($instance),
                    Metric::class,
                ),
            );
        }

        return $instance;
    }

    /**
     * @return array<string, class-string<Metric>>
     */
    public static function aliases(): array
    {
        return self::ALIASES;
    }
}
