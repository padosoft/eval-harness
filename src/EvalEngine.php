<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness;

use Closure;
use Illuminate\Contracts\Container\Container;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
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
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
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
     * @param  SampleRunner|callable  $systemUnderTest  Legacy callables receive sample input; callables typed as SampleInvocation receive the runner DTO.
     */
    public function run(string $datasetName, callable|SampleRunner $systemUnderTest): EvalReport
    {
        $dataset = $this->getDataset($datasetName);

        $sampleRunner = $this->resolveSampleRunner($systemUnderTest);
        $callableExpectsSampleInvocation = $sampleRunner === null
            && $this->callableExpectsSampleInvocation($systemUnderTest);
        $sampleInvocations = $this->sampleInvocationsFor(
            samples: $dataset->samples,
            usesSampleInvocation: $sampleRunner instanceof SampleRunner || $callableExpectsSampleInvocation,
        );

        return $this->scoreDataset(
            datasetName: $datasetName,
            dataset: $dataset,
            actualOutputForSample: fn (DatasetSample $sample, int $index): string => $this->runSample(
                systemUnderTest: $systemUnderTest,
                sample: $sample,
                sampleInvocation: $sampleInvocations[$index] ?? null,
                sampleRunner: $sampleRunner,
                callableExpectsSampleInvocation: $callableExpectsSampleInvocation,
            ),
        );
    }

    /**
     * Score precomputed sample outputs without invoking a system-under-test.
     *
     * @param  array<array-key, mixed>  $actualOutputs  Map of sample id to actual output string.
     */
    public function scoreOutputs(string $datasetName, array $actualOutputs): EvalReport
    {
        $dataset = $this->getDataset($datasetName);
        $outputs = $this->savedOutputsForDataset($datasetName, $dataset, $actualOutputs);

        return $this->scoreDataset(
            datasetName: $datasetName,
            dataset: $dataset,
            actualOutputForSample: static fn (DatasetSample $sample): string => $outputs[$sample->id],
        );
    }

    /**
     * @param  callable(DatasetSample, int): string  $actualOutputForSample
     */
    private function scoreDataset(string $datasetName, GoldenDataset $dataset, callable $actualOutputForSample): EvalReport
    {
        $startedAt = microtime(true);

        $sampleResults = [];
        $failures = [];

        foreach ($dataset->samples as $index => $sample) {
            $actualOutput = $actualOutputForSample($sample, $index);
            if (! is_string($actualOutput)) {
                throw new EvalRunException(sprintf(
                    "Actual output for sample '%s' must be a string; got %s.",
                    $sample->id,
                    get_debug_type($actualOutput),
                ));
            }

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
     * @param  array<array-key, mixed>  $actualOutputs
     * @return array<string, string>
     */
    private function savedOutputsForDataset(string $datasetName, GoldenDataset $dataset, array $actualOutputs): array
    {
        $outputs = [];
        foreach ($actualOutputs as $sampleId => $actualOutput) {
            $normalizedSampleId = trim((string) $sampleId);
            if ($normalizedSampleId === '') {
                throw new EvalRunException(sprintf(
                    "Saved outputs for dataset '%s' contain an empty sample id.",
                    $datasetName,
                ));
            }

            if (! is_string($actualOutput)) {
                throw new EvalRunException(sprintf(
                    "Saved output for sample '%s' in dataset '%s' must be a string; got %s.",
                    $normalizedSampleId,
                    $datasetName,
                    get_debug_type($actualOutput),
                ));
            }

            if (array_key_exists($normalizedSampleId, $outputs)) {
                throw new EvalRunException(sprintf(
                    "Saved outputs for dataset '%s' contain duplicate sample id '%s'.",
                    $datasetName,
                    $normalizedSampleId,
                ));
            }

            $outputs[$normalizedSampleId] = $actualOutput;
        }

        $expectedSampleIds = [];
        $missingSampleIds = [];
        foreach ($dataset->samples as $sample) {
            $expectedSampleIds[$sample->id] = true;
            if (! array_key_exists($sample->id, $outputs)) {
                $missingSampleIds[] = $sample->id;
            }
        }

        if ($missingSampleIds !== []) {
            throw new EvalRunException(sprintf(
                "Saved outputs for dataset '%s' are missing sample ids: %s.",
                $datasetName,
                implode(', ', $missingSampleIds),
            ));
        }

        $unknownSampleIds = [];
        foreach ($outputs as $sampleId => $_output) {
            if (! isset($expectedSampleIds[$sampleId])) {
                $unknownSampleIds[] = (string) $sampleId;
            }
        }

        if ($unknownSampleIds !== []) {
            throw new EvalRunException(sprintf(
                "Saved outputs for dataset '%s' contain unknown sample ids: %s.",
                $datasetName,
                implode(', ', $unknownSampleIds),
            ));
        }

        return $outputs;
    }

    /**
     * @param  SampleRunner|callable  $systemUnderTest  Legacy callables receive sample input; callables typed as SampleInvocation receive the runner DTO.
     */
    private function runSample(
        callable|SampleRunner $systemUnderTest,
        DatasetSample $sample,
        ?SampleInvocation $sampleInvocation,
        ?SampleRunner $sampleRunner,
        bool $callableExpectsSampleInvocation,
    ): string {
        if ($sampleRunner instanceof SampleRunner) {
            $actualOutput = $sampleRunner->run($this->requireSampleInvocation($sampleInvocation, $sample));
        } elseif ($callableExpectsSampleInvocation) {
            $actualOutput = $systemUnderTest($this->requireSampleInvocation($sampleInvocation, $sample));
        } else {
            $actualOutput = $systemUnderTest($sample->input);
        }

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
     * @param  list<DatasetSample>  $samples
     * @return list<SampleInvocation>
     */
    private function sampleInvocationsFor(array $samples, bool $usesSampleInvocation): array
    {
        if (! $usesSampleInvocation) {
            return [];
        }

        $sampleInvocations = [];
        foreach ($samples as $sample) {
            $sampleInvocations[] = SampleInvocation::fromDatasetSample($sample);
        }

        return $sampleInvocations;
    }

    private function requireSampleInvocation(?SampleInvocation $sampleInvocation, DatasetSample $sample): SampleInvocation
    {
        if ($sampleInvocation instanceof SampleInvocation) {
            return $sampleInvocation;
        }

        throw new EvalRunException(
            sprintf("SampleInvocation for sample '%s' was not prepared before runner dispatch.", $sample->id),
        );
    }

    private function resolveSampleRunner(callable|SampleRunner $systemUnderTest): ?SampleRunner
    {
        if ($systemUnderTest instanceof SampleRunner) {
            return $systemUnderTest;
        }

        if (! is_array($systemUnderTest)) {
            return null;
        }

        $target = $systemUnderTest[0];
        $method = $systemUnderTest[1];

        if ($target instanceof SampleRunner && $method === 'run') {
            return $target;
        }

        return null;
    }

    private function callableExpectsSampleInvocation(callable $systemUnderTest): bool
    {
        $reflection = $this->reflectionForCallable($systemUnderTest);
        if (! $reflection instanceof ReflectionFunctionAbstract) {
            return false;
        }

        $parameter = $reflection->getParameters()[0] ?? null;
        if ($parameter === null) {
            return false;
        }

        $type = $parameter->getType();
        if ($type instanceof ReflectionNamedType) {
            return $type->getName() === SampleInvocation::class;
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($unionType instanceof ReflectionNamedType && $unionType->getName() === SampleInvocation::class) {
                    return true;
                }
            }
        }

        return false;
    }

    private function reflectionForCallable(callable $systemUnderTest): ?ReflectionFunctionAbstract
    {
        try {
            if ($systemUnderTest instanceof Closure) {
                return new ReflectionFunction($systemUnderTest);
            }

            if (is_array($systemUnderTest)) {
                $target = $systemUnderTest[0];
                $method = $systemUnderTest[1];

                return new ReflectionMethod($target, $method);
            }

            if (is_string($systemUnderTest)) {
                if (str_contains($systemUnderTest, '::')) {
                    return new ReflectionMethod($systemUnderTest);
                }

                return new ReflectionFunction($systemUnderTest);
            }

            if (is_object($systemUnderTest) && method_exists($systemUnderTest, '__invoke')) {
                return new ReflectionMethod($systemUnderTest, '__invoke');
            }
        } catch (\ReflectionException) {
            return null;
        }

        return null;
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
