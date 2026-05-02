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
 * Three input shapes are supported:
 *   1. Metric instance — returned verbatim. Used when the caller
 *      needs custom constructor wiring.
 *   2. FQCN string — resolved through the container so constructor
 *      dependencies (HTTP client, config) are auto-wired.
 *   3. Alias string — looked up against the static map below, then
 *      resolved through the container.
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

        $class = self::ALIASES[$spec] ?? $spec;

        if (! class_exists($class)) {
            throw new MetricException(
                sprintf(
                    "Metric '%s' resolves to class '%s' which does not exist.",
                    $spec,
                    $class,
                ),
            );
        }

        $instance = $this->container->make($class);

        if (! $instance instanceof Metric) {
            throw new MetricException(
                sprintf(
                    "Metric '%s' resolves to '%s' which does not implement %s.",
                    $spec,
                    $class,
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
