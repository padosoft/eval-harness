<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Datasets;

use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Metrics\MetricResolver;

/**
 * Fluent dataset builder used by the public Eval facade.
 *
 * Lifecycle:
 *
 *   $eval->dataset('rag.factuality.fy2026')
 *        ->loadFromYaml('eval/golden/factuality.yml')
 *        ->withMetrics(['exact-match', 'llm-as-judge'])
 *        ->register();
 *
 * The builder is single-use: once `register()` is called, the
 * resolved {@see GoldenDataset} is stored on the engine and the
 * builder reference can be discarded.
 *
 * Mutation order doesn't matter — `loadFromYaml()` and
 * `withMetrics()` can be called in either order — but `register()`
 * must come last and asserts both calls happened.
 */
final class DatasetBuilder
{
    private ?ParsedDatasetDefinition $parsed = null;

    /** @var list<string|Metric>|null */
    private ?array $metricSpecs = null;

    /** @var list<DatasetSample>|null */
    private ?array $explicitSamples = null;

    private ?string $explicitName = null;

    public function __construct(
        private readonly EvalEngine $engine,
        private readonly MetricResolver $metricResolver,
        private readonly YamlDatasetLoader $yamlLoader,
        private readonly string $name,
    ) {}

    public function loadFromYaml(string $path): self
    {
        $this->parsed = $this->yamlLoader->loadFile($path);

        return $this;
    }

    public function loadFromYamlString(string $yaml): self
    {
        $this->parsed = $this->yamlLoader->loadString($yaml);

        return $this;
    }

    /**
     * Programmatic alternative to YAML loading. Useful for tests and
     * for callers building a dataset from a database query.
     *
     * @param  list<DatasetSample>  $samples
     */
    public function withSamples(array $samples): self
    {
        if ($samples === []) {
            throw new DatasetSchemaException(
                'withSamples() requires at least one DatasetSample.',
            );
        }

        $seen = [];
        foreach ($samples as $sample) {
            if (isset($seen[$sample->id])) {
                throw new DatasetSchemaException(
                    sprintf("Duplicate sample id '%s' in withSamples() input.", $sample->id),
                );
            }
            $seen[$sample->id] = true;
        }

        $this->explicitSamples = $samples;
        $this->explicitName = $this->name;

        return $this;
    }

    /**
     * Declare which metrics score this dataset.
     *
     * Each entry is either:
     *   - a string alias resolved by {@see MetricResolver} (e.g.
     *     'exact-match', 'cosine-embedding', 'llm-as-judge'),
     *   - a fully-qualified Metric class name (resolved via the
     *     container so dependencies are auto-wired),
     *   - or a Metric instance constructed by the caller (max
     *     control, useful when the metric needs custom config).
     *
     * @param  list<string|Metric>  $metrics
     */
    public function withMetrics(array $metrics): self
    {
        if ($metrics === []) {
            throw new DatasetSchemaException(
                'withMetrics() requires at least one metric.',
            );
        }

        $this->metricSpecs = $metrics;

        return $this;
    }

    public function register(): GoldenDataset
    {
        if ($this->parsed === null && $this->explicitSamples === null) {
            throw new DatasetSchemaException(
                sprintf(
                    "Dataset '%s' has no samples. Call loadFromYaml() / loadFromYamlString() / withSamples() before register().",
                    $this->name,
                ),
            );
        }

        if ($this->metricSpecs === null) {
            throw new DatasetSchemaException(
                sprintf(
                    "Dataset '%s' has no metrics. Call withMetrics([...]) before register().",
                    $this->name,
                ),
            );
        }

        // PHPStan note: by the guard above, when $explicitSamples
        // is null then $parsed is non-null, and vice versa. The
        // ?: chain just picks whichever was set.
        $samples = $this->explicitSamples ?? ($this->parsed !== null ? $this->parsed->samples : []);
        $name = $this->explicitName ?? ($this->parsed !== null ? $this->parsed->name : $this->name);

        $metrics = array_map(
            fn ($spec): Metric => $this->metricResolver->resolve($spec),
            $this->metricSpecs,
        );

        $dataset = new GoldenDataset(
            name: $name,
            samples: $samples,
            metrics: array_values($metrics),
        );

        $this->engine->registerDataset($dataset);

        return $dataset;
    }
}
