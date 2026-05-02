<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness;

use Illuminate\Contracts\Container\Container;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Datasets\DatasetBuilder;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\GoldenDataset;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\SampleFailure;
use Padosoft\EvalHarness\Reports\SampleResult;
use Throwable;

/**
 * Engine — single source of truth for registered datasets and the
 * orchestration of `run()` against a system-under-test callable.
 *
 * Public surface (matches the README quick-start):
 *
 *   $engine->dataset('rag.factuality.fy2026')
 *          ->loadFromYaml('eval/golden/factuality.yml')
 *          ->withMetrics(['exact-match', 'llm-as-judge'])
 *          ->register();
 *
 *   $report = $engine->run('rag.factuality.fy2026', fn (array $in) => MyApp::answer($in['question']));
 *
 * Concurrency: the engine is intentionally single-threaded. Parallel
 * execution can be layered later (worker pool / queue) through the
 * SampleRunner contract without changing the legacy callable API.
 * Sequential execution keeps deterministic ordering across runs,
 * which is essential for reproducible CI.
 */
final class EvalEngine
{
    /** @var array<string, GoldenDataset> */
    private array $datasets = [];

    public function __construct(
        private readonly Container $container,
        private readonly MetricResolver $metricResolver,
        private readonly YamlDatasetLoader $yamlLoader,
    ) {}

    public function dataset(string $name): DatasetBuilder
    {
        return new DatasetBuilder(
            engine: $this,
            metricResolver: $this->metricResolver,
            yamlLoader: $this->yamlLoader,
            name: $name,
        );
    }

    public function registerDataset(GoldenDataset $dataset): void
    {
        $this->datasets[$dataset->name] = $dataset;
    }

    public function hasDataset(string $name): bool
    {
        return isset($this->datasets[$name]);
    }

    public function getDataset(string $name): GoldenDataset
    {
        if (! isset($this->datasets[$name])) {
            throw new EvalRunException(
                sprintf("Dataset '%s' is not registered. Call \$eval->dataset(...)->register() first.", $name),
            );
        }

        return $this->datasets[$name];
    }

    /**
     * @return list<string>
     */
    public function registeredDatasetNames(): array
    {
        return array_keys($this->datasets);
    }

    /**
     * Run an eval pass.
     *
     * @param  SampleRunner|callable  $systemUnderTest  Callables receive the sample input array and must return a string.
     */
    public function run(string $datasetName, callable|SampleRunner $systemUnderTest): EvalReport
    {
        $dataset = $this->getDataset($datasetName);

        $startedAt = microtime(true);

        $sampleResults = [];
        $failures = [];

        foreach ($dataset->samples as $sample) {
            $actualOutput = $this->runSample($systemUnderTest, $sample);

            $metricScores = [];
            foreach ($dataset->metrics as $metric) {
                try {
                    $metricScores[$metric->name()] = $metric->score($sample, $actualOutput);
                } catch (Throwable $e) {
                    $failures[] = new SampleFailure(
                        sampleId: $sample->id,
                        metricName: $metric->name(),
                        error: $e->getMessage(),
                    );
                }
            }

            $sampleResults[] = new SampleResult(
                sample: $sample,
                actualOutput: $actualOutput,
                metricScores: $metricScores,
            );
        }

        return new EvalReport(
            datasetName: $datasetName,
            sampleResults: $sampleResults,
            failures: $failures,
            startedAt: $startedAt,
            finishedAt: microtime(true),
            datasetSchemaVersion: $dataset->schemaVersion,
        );
    }

    /**
     * @param  SampleRunner|callable  $systemUnderTest  Callables receive the sample input array and must return a string.
     */
    private function runSample(callable|SampleRunner $systemUnderTest, DatasetSample $sample): string
    {
        $actualOutput = $systemUnderTest instanceof SampleRunner
            ? $systemUnderTest->run($sample)
            : $systemUnderTest($sample->input);

        if (! is_string($actualOutput)) {
            throw new EvalRunException(
                sprintf(
                    "System-under-test for sample '%s' must return a string; got %s.",
                    $sample->id,
                    get_debug_type($actualOutput),
                ),
            );
        }

        return $actualOutput;
    }

    /**
     * Drop the registry — primarily for tests that re-use the engine.
     */
    public function reset(): void
    {
        $this->datasets = [];
    }

    /** @internal Used by the EvalCommand to resolve callables out of the container. */
    public function container(): Container
    {
        return $this->container;
    }
}
