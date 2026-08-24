<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Batches\LazyParallelBatch;
use Padosoft\EvalHarness\Batches\RateLimitWindow;
use Padosoft\EvalHarness\Batches\SerialBatch;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Costs\CostLedger;
use Padosoft\EvalHarness\Costs\Events\EvalRunCosted;
use Padosoft\EvalHarness\Costs\PriceBook;
use Padosoft\EvalHarness\Costs\RunBudget;
use Padosoft\EvalHarness\Costs\RunCost;
use Padosoft\EvalHarness\Datasets\DatasetBuilder;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\GoldenDataset;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\EvalSets\EvalSetDefinition;
use Padosoft\EvalHarness\EvalSets\EvalSetManifest;
use Padosoft\EvalHarness\EvalSets\EvalSetRunner;
use Padosoft\EvalHarness\EvalSets\EvalSetRunResult;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Metrics\Trajectory\TrajectoryMetric;
use Padosoft\EvalHarness\Outputs\SavedOutputs;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\SampleFailure;
use Padosoft\EvalHarness\Reports\SampleResult;
use Padosoft\EvalHarness\Support\MetricUsageDetails;
use Padosoft\EvalHarness\Support\RuntimeOptions;
use Padosoft\EvalHarness\Trajectory\Trajectory;
use Padosoft\EvalHarness\Trajectory\TrajectoryRecorder;
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
        private readonly ?TrajectoryRecorder $trajectories = null,
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
     * @param  int|null  $repetitions  Executions per row; null uses the dataset's own setting (default 1).
     */
    public function run(
        string $datasetName,
        callable|SampleRunner $systemUnderTest,
        ?int $repetitions = null,
        ?float $budgetUsd = null,
    ): EvalReport {
        return $this->runBatch($datasetName, $systemUnderTest, BatchOptions::serial(), $repetitions, $budgetUsd);
    }

    /**
     * Run an eval pass through an explicit batch strategy.
     *
     * When the dataset (or the caller) asks for more than one repetition, the
     * whole pass is executed that many times and the executions are merged into
     * a single report. Repeating the pass rather than the individual sample is
     * deliberate: it keeps every batch mode, rate limit and checkpoint working
     * unchanged, and it spreads the repetitions of one row across the run
     * instead of firing them back-to-back — which matters, because three calls
     * in the same second against a provider that caches aggressively are closer
     * to one measurement than to three.
     *
     * @param  SampleRunner|callable  $systemUnderTest  Legacy callables receive sample input; callables typed as SampleInvocation receive the runner DTO.
     * @param  int|null  $repetitions  Executions per row; null uses the dataset's own setting (default 1).
     * @param  float|null  $budgetUsd  Stop the run once observable provider spend passes this; null runs to completion.
     */
    public function runBatch(
        string $datasetName,
        callable|SampleRunner $systemUnderTest,
        ?BatchOptions $batchOptions = null,
        ?int $repetitions = null,
        ?float $budgetUsd = null,
    ): EvalReport {
        $dataset = $this->getDataset($datasetName);
        $passes = $this->resolveRepetitions($dataset, $repetitions);
        $budget = RunBudget::of($budgetUsd, new CostLedger($this->prices()));

        if ($passes === 1) {
            $pass = $this->runSinglePass($datasetName, $systemUnderTest, $batchOptions, 0, budget: $budget);

            return $this->finish(
                datasetName: $datasetName,
                dataset: $dataset,
                sampleResults: $pass->sampleResults,
                failures: $pass->failures,
                startedAt: $pass->startedAt,
                budget: $budget,
            );
        }

        $startedAt = microtime(true);
        $sampleResults = [];
        $failures = [];

        // One limiter for the whole run, not one per pass: a rate limit is a
        // promise about how hard a provider gets hit, and three passes each
        // opening a fresh window would break that promise by exactly the
        // repetition count.
        $rateLimiter = LazyParallelBatch::windowFor($batchOptions ?? BatchOptions::serial());

        for ($repetition = 0; $repetition < $passes; $repetition++) {
            $report = $this->runSinglePass($datasetName, $systemUnderTest, $batchOptions, $repetition, $rateLimiter, $budget);

            // Appended in place rather than array_merge()d: merging inside the
            // loop reallocates the whole accumulator on every pass, which is
            // quadratic in repetitions for no benefit.
            foreach ($report->sampleResults as $result) {
                $sampleResults[] = $result;
            }
            foreach ($report->failures as $failure) {
                $failures[] = $failure;
            }

            // One budget spans every pass, so a halt in pass two ends the run
            // rather than starting pass three with an empty wallet.
            if ($budget->wasHalted()) {
                break;
            }
        }

        return $this->finish(
            datasetName: $datasetName,
            dataset: $dataset,
            sampleResults: $sampleResults,
            failures: $failures,
            startedAt: $startedAt,
            budget: $budget,
        );
    }

    /**
     * Executions per row for this run: explicit override, else the dataset's.
     *
     * An explicit zero or negative value is rejected rather than clamped. The
     * CLI, the YAML loader, the builder and GoldenDataset all refuse those, and
     * a programmatic caller quietly getting one execution out of
     * `repetitions: 0` would spend the tokens and produce a report that hides
     * its own misconfiguration.
     */
    private function resolveRepetitions(GoldenDataset $dataset, ?int $repetitions): int
    {
        if ($repetitions === null) {
            return max(1, $dataset->repetitions);
        }

        if ($repetitions < 1) {
            throw new EvalRunException(sprintf(
                'Repetitions must be at least 1; got %d.',
                $repetitions,
            ));
        }

        return $repetitions;
    }

    private function runSinglePass(
        string $datasetName,
        callable|SampleRunner $systemUnderTest,
        ?BatchOptions $batchOptions,
        int $repetition,
        ?RateLimitWindow $rateLimiter = null,
        ?RunBudget $budget = null,
    ): EvalReport {
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
                repetition: $repetition,
                budget: $budget,
                actualOutputs: $this->lazyParallelBatch()->run(
                    samples: $dataset->samples,
                    sampleInvocations: $sampleInvocations,
                    runner: $sampleRunner,
                    options: $batchOptions,
                    rateLimiter: $rateLimiter,
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
            repetition: $repetition,
            budget: $budget,
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
    public function scoreOutputs(
        string $datasetName,
        array|SavedOutputs $actualOutputs,
        ?int $repetitions = null,
        ?float $budgetUsd = null,
    ): EvalReport {
        $dataset = $this->getDataset($datasetName);
        $outputs = $this->savedOutputsForDataset($datasetName, $dataset, $actualOutputs);
        $this->recordSavedTrajectories($actualOutputs);
        $actualOutputForSample = static fn (DatasetSample $sample, int $_index): string => $outputs[self::sampleIdKey($sample->id)];
        $passes = $this->resolveRepetitions($dataset, $repetitions);

        // The pipeline calls are already paid for on this path, so the budget
        // here caps the *grading* bill — which for an LLM-as-judge suite over
        // saved outputs is the whole bill.
        $budget = RunBudget::of($budgetUsd, new CostLedger($this->prices()));

        if ($passes === 1) {
            $pass = $this->scoreDataset(
                datasetName: $datasetName,
                dataset: $dataset,
                startedAt: microtime(true),
                actualOutputForSample: $actualOutputForSample,
                budget: $budget,
            );

            return $this->finish(
                datasetName: $datasetName,
                dataset: $dataset,
                sampleResults: $pass->sampleResults,
                failures: $pass->failures,
                startedAt: $pass->startedAt,
                budget: $budget,
            );
        }

        // Repeating a scoring pass over fixed outputs measures the *metrics*
        // rather than the pipeline: deterministic metrics return a stddev of
        // zero by construction, and anything left moving is the judge
        // disagreeing with itself. That is the cheapest judge-stability check
        // in the package — no pipeline invocation, no new dataset.
        $startedAt = microtime(true);
        $sampleResults = [];
        $failures = [];

        for ($repetition = 0; $repetition < $passes; $repetition++) {
            $report = $this->scoreDataset(
                datasetName: $datasetName,
                dataset: $dataset,
                startedAt: microtime(true),
                actualOutputForSample: $actualOutputForSample,
                repetition: $repetition,
                budget: $budget,
            );
            foreach ($report->sampleResults as $result) {
                $sampleResults[] = $result;
            }
            foreach ($report->failures as $failure) {
                $failures[] = $failure;
            }

            if ($budget->wasHalted()) {
                break;
            }
        }

        return $this->finish(
            datasetName: $datasetName,
            dataset: $dataset,
            sampleResults: $sampleResults,
            failures: $failures,
            startedAt: $startedAt,
            budget: $budget,
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
        int $repetition = 0,
        ?RunBudget $budget = null,
    ): EvalReport {
        $batchOptions ??= BatchOptions::serial();

        if ($batchOptions->mode === BatchOptions::MODE_SERIAL) {
            return $this->scoreSerialDataset(
                datasetName: $datasetName,
                dataset: $dataset,
                startedAt: $startedAt,
                actualOutputForSample: $actualOutputForSample,
                repetition: $repetition,
                budget: $budget,
            );
        }

        return $this->scoreDatasetOutputs(
            datasetName: $datasetName,
            dataset: $dataset,
            startedAt: $startedAt,
            actualOutputs: $this->sampleOutputsForBatch($dataset, $actualOutputForSample, $batchOptions),
            repetition: $repetition,
            budget: $budget,
        );
    }

    /**
     * @param  callable(DatasetSample, int): string  $actualOutputForSample
     */
    private function scoreSerialDataset(
        string $datasetName,
        GoldenDataset $dataset,
        float $startedAt,
        callable $actualOutputForSample,
        int $repetition = 0,
        ?RunBudget $budget = null,
    ): EvalReport {
        $sampleResults = [];
        $failures = [];

        $this->serialBatch->runEach(
            samples: $dataset->samples,
            actualOutputForSample: $actualOutputForSample,
            handleOutput: function (DatasetSample $sample, int $_index, string $actualOutput) use ($dataset, $repetition, $budget, &$sampleResults, &$failures): void {
                $sampleResults[] = $this->scoreSampleResult($dataset, $sample, $actualOutput, $failures, $repetition, $budget);
            },
            // Checked after the row rather than before it: the spend that
            // crosses the line happens inside the row, and stopping before
            // scoring would pay for an answer and then throw the grade away.
            // The rows already scored are kept — they are real measurements,
            // and the report records the halt so nothing reads them as a
            // complete verdict.
            shouldStop: function () use ($budget, &$sampleResults): bool {
                if ($budget?->isExceeded() !== true) {
                    return false;
                }

                $budget->halt(count($sampleResults));

                return true;
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
    private function scoreDatasetOutputs(
        string $datasetName,
        GoldenDataset $dataset,
        float $startedAt,
        array $actualOutputs,
        int $repetition = 0,
        ?RunBudget $budget = null,
    ): EvalReport {
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

            $sampleResults[] = $this->scoreSampleResult($dataset, $sample, $actualOutput, $failures, $repetition, $budget);

            // The provider calls are already paid for on this path — the
            // outputs arrived from a batch or from a file — but grading them
            // is not free, and stopping here caps the grading bill.
            if ($budget?->isExceeded() === true) {
                $budget->halt(count($sampleResults));

                break;
            }
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
    private function scoreSampleResult(
        GoldenDataset $dataset,
        DatasetSample $sample,
        string $actualOutput,
        array &$failures,
        int $repetition = 0,
        ?RunBudget $budget = null,
    ): SampleResult {
        $trajectory = $this->trajectoryFor($sample->id, $repetition);
        $metricScores = [];
        foreach ($dataset->metrics as $metric) {
            try {
                $score = $metric instanceof TrajectoryMetric
                    ? $metric->scoreTrajectory($sample, $actualOutput, $trajectory)
                    : $metric->score($sample, $actualOutput);

                $metricScores[$metric->name()] = $score;
                $budget?->record($score->details);
            } catch (Throwable $e) {
                if ($this->shouldRaiseMetricExceptions() && $e instanceof MetricException) {
                    throw $e;
                }

                $failure = new SampleFailure(
                    sampleId: $sample->id,
                    metricName: $metric->name(),
                    error: $e->getMessage(),
                    details: MetricUsageDetails::append([], $metric),
                    repetition: $repetition,
                );

                // A metric that threw after calling a provider still spent the
                // money. Charging only for successes would let a run that
                // fails every judge call look free.
                $budget?->record($failure->details);
                $failures[] = $failure;
            }
        }

        return new SampleResult(
            sample: $sample,
            actualOutput: $actualOutput,
            metricScores: $metricScores,
            repetition: $repetition,
            trajectory: $trajectory,
        );
    }

    /**
     * Hand any trajectories that travelled with the saved outputs to the
     * recorder, so the trajectory metrics work on a file with no agent running.
     *
     * @param  array<array-key, mixed>|SavedOutputs  $actualOutputs
     */
    private function recordSavedTrajectories(array|SavedOutputs $actualOutputs): void
    {
        if (! $actualOutputs instanceof SavedOutputs) {
            return;
        }

        $trajectories = $actualOutputs->trajectories();

        if ($trajectories === []) {
            return;
        }

        $recorder = $this->trajectoryRecorder();

        if (! $recorder instanceof TrajectoryRecorder) {
            return;
        }

        foreach ($trajectories as $sampleId => $trajectory) {
            $recorder->record((string) $sampleId, $trajectory);
        }
    }

    /**
     * The trajectory recorded for this execution, if anything recorded one.
     *
     * Resolved lazily from the container rather than required in the
     * constructor: the recorder is only ever populated by a host that has an
     * agent to record, and a RAG pipeline should not have to know it exists.
     */
    private function trajectoryFor(string $sampleId, int $repetition): ?Trajectory
    {
        $recorder = $this->trajectoryRecorder();

        return $recorder?->for($sampleId, $repetition);
    }

    private function trajectoryRecorder(): ?TrajectoryRecorder
    {
        if ($this->trajectories instanceof TrajectoryRecorder) {
            return $this->trajectories;
        }

        try {
            $recorder = $this->container->make(TrajectoryRecorder::class);
        } catch (Throwable) {
            return null;
        }

        return $recorder instanceof TrajectoryRecorder ? $recorder : null;
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
    /**
     * Assemble the final report and announce what the run cost.
     *
     * The event fires whether or not anything is listening and whether or not
     * the run finished: an evaluation that halted on its budget is precisely
     * the one a FinOps listener most wants to hear about.
     *
     * @param  list<SampleResult>  $sampleResults
     * @param  list<SampleFailure>  $failures
     */
    private function finish(
        string $datasetName,
        GoldenDataset $dataset,
        array $sampleResults,
        array $failures,
        float $startedAt,
        ?RunBudget $budget,
    ): EvalReport {
        $finishedAt = microtime(true);
        $cost = $budget?->toRunCost();

        $report = new EvalReport(
            datasetName: $datasetName,
            sampleResults: $sampleResults,
            failures: $failures,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            datasetSchemaVersion: $dataset->schemaVersion,
            cost: $cost,
            budget: $budget?->outcome(),
        );

        if ($cost !== null) {
            $this->announceCost($datasetName, $report, $cost, $budget?->wasHalted() === true);
        }

        return $report;
    }

    private function announceCost(string $datasetName, EvalReport $report, RunCost $cost, bool $halted): void
    {
        $events = $this->events();

        if ($events === null) {
            return;
        }

        $events->dispatch(new EvalRunCosted(
            dataset: $datasetName,
            costCenter: $this->costCenterFor($datasetName),
            cost: $cost,
            startedAt: $report->startedAt,
            finishedAt: $report->finishedAt,
            rows: $report->totalSamples(),
            executions: $report->totalExecutions(),
            halted: $halted,
        ));
    }

    /**
     * The label eval spend is attributed under.
     *
     * `eval:<dataset>` by default, because to a provider dashboard evaluation
     * traffic is indistinguishable from production traffic — same key, same
     * model, same endpoint — and the first honest answer to "how much are we
     * spending on quality?" is otherwise "we cannot tell".
     */
    private function costCenterFor(string $datasetName): string
    {
        $template = $this->config?->get('eval-harness.costs.cost_center', 'eval:{dataset}');

        if (! is_string($template) || $template === '') {
            $template = 'eval:{dataset}';
        }

        return str_replace('{dataset}', $datasetName, $template);
    }

    private function prices(): PriceBook
    {
        /** @var PriceBook $prices */
        $prices = $this->container->make(PriceBook::class);

        return $prices;
    }

    private function events(): ?EventDispatcher
    {
        if (! $this->container->bound(EventDispatcher::class)) {
            return null;
        }

        $events = $this->container->make(EventDispatcher::class);

        return $events instanceof EventDispatcher ? $events : null;
    }

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
