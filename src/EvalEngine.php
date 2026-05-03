<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Batches\LazyParallelBatch;
use Padosoft\EvalHarness\Batches\SerialBatch;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Datasets\DatasetBuilder;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\GoldenDataset;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\EvalSets\EvalSetDefinition;
use Padosoft\EvalHarness\EvalSets\EvalSetManifest;
use Padosoft\EvalHarness\EvalSets\EvalSetRunner;
use Padosoft\EvalHarness\EvalSets\EvalSetRunResult;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Outputs\SavedOutputs;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\SampleFailure;
use Padosoft\EvalHarness\Reports\SampleResult;
use Padosoft\EvalHarness\Support\RuntimeOptions;
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
 * Batch execution: serial mode is deterministic and in-process.
 * Lazy parallel mode dispatches queue-safe SampleRunner jobs while
 * preserving the same positional output ordering for report scoring.
 */
final class EvalEngine
{
    /** @var array<string, GoldenDataset> */
    private array $datasets = [];

    private readonly SerialBatch $serialBatch;

    private readonly ?LazyParallelBatch $lazyParallelBatch;

    public function __construct(
        private readonly Container $container,
        private readonly MetricResolver $metricResolver,
        private readonly YamlDatasetLoader $yamlLoader,
        ?SerialBatch $serialBatch = null,
        ?LazyParallelBatch $lazyParallelBatch = null,
        private readonly ?ConfigRepository $config = null,
    ) {
        $this->serialBatch = $serialBatch ?? new SerialBatch;
        $this->lazyParallelBatch = $lazyParallelBatch;
    }

    public function dataset(string $name): DatasetBuilder
    {
        return new DatasetBuilder(
            engine: $this,
            metricResolver: $this->metricResolver,
            yamlLoader: $this->yamlLoader,
            name: $name,
        );
    }

    /**
     * @param  list<string>  $datasetNames
     */
    public function evalSet(string $name, array $datasetNames): EvalSetDefinition
    {
        return new EvalSetDefinition($name, $datasetNames);
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
        return $this->runBatch($datasetName, $systemUnderTest, BatchOptions::serial());
    }

    /**
     * Run an eval pass through an explicit batch strategy.
     *
     * @param  SampleRunner|callable  $systemUnderTest  Legacy callables receive sample input; callables typed as SampleInvocation receive the runner DTO.
     */
    public function runBatch(string $datasetName, callable|SampleRunner $systemUnderTest, ?BatchOptions $batchOptions = null): EvalReport
    {
        $startedAt = microtime(true);
        $dataset = $this->getDataset($datasetName);
        $batchOptions ??= BatchOptions::serial();

        $sampleRunner = $this->resolveSampleRunner($systemUnderTest);
        if ($batchOptions->mode === BatchOptions::MODE_LAZY_PARALLEL) {
            if (! $sampleRunner instanceof SampleRunner) {
                throw new EvalRunException(
                    'Lazy parallel batch mode requires a SampleRunner system-under-test; arbitrary callables and closures are not queue-serializable.',
                );
            }

            $sampleInvocations = $this->sampleInvocationsFor(
                samples: $dataset->samples,
                usesSampleInvocation: true,
            );

            return $this->scoreDatasetOutputs(
                datasetName: $datasetName,
                dataset: $dataset,
                startedAt: $startedAt,
                actualOutputs: $this->lazyParallelBatch()->run(
                    samples: $dataset->samples,
                    sampleInvocations: $sampleInvocations,
                    runner: $sampleRunner,
                    options: $batchOptions,
                ),
            );
        }

        $callableExpectsSampleInvocation = $sampleRunner === null
            && $this->callableExpectsSampleInvocation($systemUnderTest);
        $sampleInvocations = $this->sampleInvocationsFor(
            samples: $dataset->samples,
            usesSampleInvocation: $sampleRunner instanceof SampleRunner || $callableExpectsSampleInvocation,
        );

        return $this->scoreDataset(
            datasetName: $datasetName,
            dataset: $dataset,
            startedAt: $startedAt,
            actualOutputForSample: fn (DatasetSample $sample, int $index): string => $this->runSample(
                systemUnderTest: $systemUnderTest,
                sample: $sample,
                sampleInvocation: $sampleInvocations[$index] ?? null,
                sampleRunner: $sampleRunner,
                callableExpectsSampleInvocation: $callableExpectsSampleInvocation,
            ),
            batchOptions: $batchOptions,
        );
    }

    /**
     * Run a named group of registered datasets and return a resumable manifest.
     */
    public function runEvalSet(
        EvalSetDefinition $evalSet,
        callable|SampleRunner $systemUnderTest,
        ?BatchOptions $batchOptions = null,
        ?EvalSetManifest $manifest = null,
    ): EvalSetRunResult {
        return (new EvalSetRunner($this))->run(
            definition: $evalSet,
            systemUnderTest: $systemUnderTest,
            batchOptions: $batchOptions,
            manifest: $manifest,
        );
    }

    /**
     * Score precomputed sample outputs without invoking a system-under-test.
     *
     * @param  array<array-key, mixed>|SavedOutputs  $actualOutputs  Map or loaded saved-output entries.
     */
    public function scoreOutputs(string $datasetName, array|SavedOutputs $actualOutputs): EvalReport
    {
        $startedAt = microtime(true);
        $dataset = $this->getDataset($datasetName);
        $outputs = $this->savedOutputsForDataset($datasetName, $dataset, $actualOutputs);

        return $this->scoreDataset(
            datasetName: $datasetName,
            dataset: $dataset,
            startedAt: $startedAt,
            actualOutputForSample: static fn (DatasetSample $sample, int $_index): string => $outputs[self::sampleIdKey($sample->id)],
        );
    }

    /**
     * @param  callable(DatasetSample, int): string  $actualOutputForSample
     */
    private function scoreDataset(
        string $datasetName,
        GoldenDataset $dataset,
        float $startedAt,
        callable $actualOutputForSample,
        ?BatchOptions $batchOptions = null,
    ): EvalReport {
        $batchOptions ??= BatchOptions::serial();

        if ($batchOptions->mode === BatchOptions::MODE_SERIAL) {
            return $this->scoreSerialDataset(
                datasetName: $datasetName,
                dataset: $dataset,
                startedAt: $startedAt,
                actualOutputForSample: $actualOutputForSample,
            );
        }

        return $this->scoreDatasetOutputs(
            datasetName: $datasetName,
            dataset: $dataset,
            startedAt: $startedAt,
            actualOutputs: $this->sampleOutputsForBatch($dataset, $actualOutputForSample, $batchOptions),
        );
    }

    /**
     * @param  callable(DatasetSample, int): string  $actualOutputForSample
     */
    private function scoreSerialDataset(string $datasetName, GoldenDataset $dataset, float $startedAt, callable $actualOutputForSample): EvalReport
    {
        $sampleResults = [];
        $failures = [];

        $this->serialBatch->runEach(
            samples: $dataset->samples,
            actualOutputForSample: $actualOutputForSample,
            handleOutput: function (DatasetSample $sample, int $_index, string $actualOutput) use ($dataset, &$sampleResults, &$failures): void {
                $sampleResults[] = $this->scoreSampleResult($dataset, $sample, $actualOutput, $failures);
            },
        );

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
     * @param  list<string>  $actualOutputs
     */
    private function scoreDatasetOutputs(string $datasetName, GoldenDataset $dataset, float $startedAt, array $actualOutputs): EvalReport
    {
        $sampleResults = [];
        $failures = [];

        foreach ($dataset->samples as $index => $sample) {
            if (! array_key_exists($index, $actualOutputs)) {
                throw new EvalRunException(sprintf(
                    "Batch output for sample '%s' at index %d is missing.",
                    $sample->id,
                    $index,
                ));
            }

            $actualOutput = $actualOutputs[$index];

            $sampleResults[] = $this->scoreSampleResult($dataset, $sample, $actualOutput, $failures);
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
     * @param  list<SampleFailure>  $failures
     */
    private function scoreSampleResult(GoldenDataset $dataset, DatasetSample $sample, string $actualOutput, array &$failures): SampleResult
    {
        $metricScores = [];
        foreach ($dataset->metrics as $metric) {
            try {
                $metricScores[$metric->name()] = $metric->score($sample, $actualOutput);
            } catch (Throwable $e) {
                if ($this->shouldRaiseMetricExceptions()) {
                    throw $e;
                }

                $failures[] = new SampleFailure(
                    sampleId: $sample->id,
                    metricName: $metric->name(),
                    error: $e->getMessage(),
                );
            }
        }

        return new SampleResult(
            sample: $sample,
            actualOutput: $actualOutput,
            metricScores: $metricScores,
        );
    }

    private function shouldRaiseMetricExceptions(): bool
    {
        if ($this->config === null) {
            return false;
        }

        return RuntimeOptions::raiseMetricExceptions($this->config);
    }

    /**
     * @param  callable(DatasetSample, int): string  $actualOutputForSample
     * @return list<string>
     */
    private function sampleOutputsForBatch(GoldenDataset $dataset, callable $actualOutputForSample, BatchOptions $batchOptions): array
    {
        if ($batchOptions->mode === BatchOptions::MODE_SERIAL) {
            return $this->serialBatch->run($dataset->samples, $actualOutputForSample);
        }

        throw new EvalRunException(sprintf(
            "Unsupported batch mode '%s'.",
            $batchOptions->mode,
        ));
    }

    private function lazyParallelBatch(): LazyParallelBatch
    {
        if ($this->lazyParallelBatch instanceof LazyParallelBatch) {
            return $this->lazyParallelBatch;
        }

        try {
            $batch = $this->container->make(LazyParallelBatch::class);
        } catch (Throwable $e) {
            throw new EvalRunException(
                sprintf(
                    'Failed to resolve lazy parallel batch services: %s. Ensure the package service provider is registered and queue services are available.',
                    $e->getMessage() !== '' ? $e->getMessage() : $e::class,
                ),
                previous: $e,
            );
        }

        if (! $batch instanceof LazyParallelBatch) {
            throw new EvalRunException(sprintf(
                'Container binding for %s must resolve to %s; got %s.',
                LazyParallelBatch::class,
                LazyParallelBatch::class,
                get_debug_type($batch),
            ));
        }

        return $batch;
    }

    /**
     * @param  array<array-key, mixed>|SavedOutputs  $actualOutputs
     * @return array<string, string>
     */
    private function savedOutputsForDataset(string $datasetName, GoldenDataset $dataset, array|SavedOutputs $actualOutputs): array
    {
        $savedOutputs = $actualOutputs instanceof SavedOutputs
            ? $actualOutputs
            : $this->savedOutputsFromArray($datasetName, $dataset, $actualOutputs);

        $outputs = [];
        $outputSampleIds = [];
        foreach ($savedOutputs->entries() as $entry) {
            $key = self::sampleIdKey($entry['id']);
            $outputs[$key] = $entry['actual_output'];
            $outputSampleIds[$key] = $entry['id'];
        }

        $expectedSampleIds = [];
        $missingSampleIds = [];
        foreach ($dataset->samples as $sample) {
            $key = self::sampleIdKey($sample->id);
            $expectedSampleIds[$key] = true;
            if (! array_key_exists($key, $outputs)) {
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
        foreach ($outputs as $sampleIdKey => $_output) {
            if (! isset($expectedSampleIds[$sampleIdKey])) {
                $unknownSampleIds[] = $outputSampleIds[$sampleIdKey];
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
     * @param  array<array-key, mixed>  $actualOutputs
     */
    private function savedOutputsFromArray(string $datasetName, GoldenDataset $dataset, array $actualOutputs): SavedOutputs
    {
        if ($actualOutputs !== [] && array_is_list($actualOutputs)) {
            $expectedIds = array_fill_keys(
                array_map(
                    static fn (DatasetSample $sample): string => self::sampleIdKey($sample->id),
                    $dataset->samples,
                ),
                true,
            );
            $listIds = array_map(
                static fn (int $index): string => self::sampleIdKey((string) $index),
                array_keys($actualOutputs),
            );
            $listIdSet = array_fill_keys($listIds, true);

            if (count($expectedIds) !== count($listIdSet) || array_diff_key($expectedIds, $listIdSet) !== []) {
                throw new EvalRunException(sprintf(
                    "Saved outputs for dataset '%s' must be a keyed map of sample id to output string.",
                    $datasetName,
                ));
            }

            $entries = [];
            foreach ($actualOutputs as $index => $actualOutput) {
                if (! is_string($actualOutput)) {
                    throw new EvalRunException(sprintf(
                        "Saved output for sample '%s' in dataset '%s' must be a string; got %s.",
                        (string) $index,
                        $datasetName,
                        get_debug_type($actualOutput),
                    ));
                }

                $entries[] = ['id' => (string) $index, 'actual_output' => $actualOutput];
            }

            return new SavedOutputs($entries);
        }

        return SavedOutputs::fromMap($actualOutputs, "dataset '{$datasetName}'");
    }

    private static function sampleIdKey(string $sampleId): string
    {
        return sprintf('sample-id:%d:%s', strlen($sampleId), $sampleId);
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
