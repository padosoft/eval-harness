<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Jobs\EvaluateSampleJob;
use Random\RandomException;
use ReflectionClass;
use ReflectionNamedType;
use Throwable;

/**
 * Queue-backed sample batch runner with deterministic output assembly.
 */
final class LazyParallelBatch
{
    private const DEFAULT_RESULT_TTL_SECONDS = 3600;

    private const DEFAULT_WAIT_TIMEOUT_SECONDS = 60;

    private const INITIAL_POLL_INTERVAL_MICROSECONDS = 50_000;

    private const MAX_POLL_INTERVAL_MICROSECONDS = 1_000_000;

    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly BatchResultStore $resultStore,
        private readonly ?Container $container = null,
        private readonly int $resultTtlSeconds = self::DEFAULT_RESULT_TTL_SECONDS,
        private readonly int $defaultWaitTimeoutSeconds = self::DEFAULT_WAIT_TIMEOUT_SECONDS,
        private readonly BatchProgressReporter $progressReporter = new NullBatchProgressReporter,
    ) {
        if ($resultTtlSeconds < 1) {
            throw new EvalRunException('Batch result TTL must be greater than or equal to 1 second.');
        }

        if ($defaultWaitTimeoutSeconds < 1) {
            throw new EvalRunException('Lazy parallel batch wait timeout must be greater than or equal to 1 second.');
        }
    }

    /**
     * Dispatch sample jobs and wait for the shared result store to contain every output.
     *
     * @param  list<DatasetSample>  $samples
     * @param  list<SampleInvocation>  $sampleInvocations
     * @return list<string>
     */
    public function run(array $samples, array $sampleInvocations, SampleRunner $runner, BatchOptions $options): array
    {
        $this->assertLazyParallelOptions($options);
        $samples = $this->assertSampleList($samples);
        $this->assertInvocationList($samples, $sampleInvocations);

        $runnerClass = $this->runnerClassFor($runner);
        $batchId = $this->newBatchId();
        $sampleCount = count($samples);
        $outputsByIndex = [];
        $waitTimeoutSeconds = $options->waitTimeoutSeconds ?? $this->defaultWaitTimeoutSeconds;
        $resultTtlSeconds = $this->resultTtlSecondsFor($options, $waitTimeoutSeconds, $sampleCount);
        $completed = false;

        $rateLimiter = $this->rateLimitWindow($options);
        $checkpointEvery = $options->checkpointEvery;
        $samplesCompleted = 0;
        $nextCheckpointThreshold = $checkpointEvery;

        $this->startResults($batchId, $sampleCount, $resultTtlSeconds);

        try {
            foreach (array_chunk($samples, $options->effectiveChunkSize(), preserve_keys: true) as $sampleWindow) {
                // The chunk deadline covers BOTH dispatch (which can
                // include rate-limit pauses) and result collection so
                // the wait timeout always bounds the per-window wall
                // clock. Without this, a low rateLimit with a large
                // chunk could spend most of the operator-supplied
                // budget sleeping inside dispatchSampleJobs() before
                // collection started.
                $chunkDeadline = microtime(true) + $waitTimeoutSeconds;

                try {
                    $this->dispatchSampleJobs(
                        batchId: $batchId,
                        samples: $sampleWindow,
                        sampleInvocations: $sampleInvocations,
                        runnerClass: $runnerClass,
                        options: $options,
                        resultTtlSeconds: $resultTtlSeconds,
                        rateLimiter: $rateLimiter,
                    );
                } catch (Throwable $e) {
                    $this->throwStoredFailureOrDispatchException(
                        batchId: $batchId,
                        sampleCount: $sampleCount,
                        indexes: $this->sampleIndexes($sampleWindow),
                        previous: $e,
                    );
                }

                if (microtime(true) >= $chunkDeadline) {
                    // A real sample failure recorded by an earlier
                    // worker is more useful than the deadline
                    // diagnostic, so surface it first.
                    $failure = $this->firstFailure(
                        $batchId,
                        $sampleCount,
                        $this->sampleIndexes($sampleWindow),
                    );
                    if ($failure !== null) {
                        throw new EvalRunException(sprintf(
                            "Lazy parallel batch job for sample '%s' failed: %s.",
                            $failure['sample_id'],
                            $failure['error'],
                        ));
                    }

                    // Diagnostic stays neutral so library callers using
                    // EvalEngine::runBatch() / runEvalSet() do not get
                    // CLI-only remediation guidance.
                    throw new EvalRunException(sprintf(
                        "Lazy parallel batch '%s' chunk dispatch consumed the full %s wait timeout for %d sample(s) before result collection started; lower the chunk size, relax the rate limit, or raise the wait timeout.",
                        $batchId,
                        $this->secondsLabel($waitTimeoutSeconds),
                        count($sampleWindow),
                    ));
                }

                $outputsByIndex += $this->waitForIndexedOutputs(
                    batchId: $batchId,
                    samples: $sampleWindow,
                    sampleCount: $sampleCount,
                    deadlineMicrotime: $chunkDeadline,
                    timeoutSecondsForDiagnostic: $waitTimeoutSeconds,
                );

                $samplesCompleted += count($sampleWindow);
                $nextCheckpointThreshold = $this->reportCheckpointThresholdsCrossed(
                    batchId: $batchId,
                    samplesCompleted: $samplesCompleted,
                    totalSamples: $sampleCount,
                    checkpointEvery: $checkpointEvery,
                    nextCheckpointThreshold: $nextCheckpointThreshold,
                );
            }

            $this->reportCheckpointFinalIfNeeded(
                batchId: $batchId,
                samplesCompleted: $samplesCompleted,
                totalSamples: $sampleCount,
                checkpointEvery: $checkpointEvery,
            );

            ksort($outputsByIndex);

            $outputs = [];
            foreach ($samples as $index => $sample) {
                if (! array_key_exists($index, $outputsByIndex)) {
                    throw new EvalRunException(sprintf(
                        "Batch output for sample '%s' at index %d is missing.",
                        $sample->id,
                        $index,
                    ));
                }

                $outputs[] = $outputsByIndex[$index];
            }

            $completed = true;
            $this->finishResultsSafely($batchId, $sampleCount, $resultTtlSeconds);

            return $outputs;
        } catch (Throwable $e) {
            if (! $completed) {
                $this->abortResultsSafely($batchId, $sampleCount, $resultTtlSeconds);
            }

            throw $e;
        }
    }

    /**
     * Dispatch every sample job and return the opaque batch id for later collection.
     *
     * This method intentionally does not wait between concurrency windows; it is
     * useful for Queue::fake() assertions and external schedulers. Engine runs
     * should use run(), which applies the concurrency window before collecting.
     *
     * @param  list<DatasetSample>  $samples
     * @param  list<SampleInvocation>  $sampleInvocations
     */
    public function dispatch(array $samples, array $sampleInvocations, SampleRunner $runner, BatchOptions $options): string
    {
        $this->assertLazyParallelOptions($options);
        $samples = $this->assertSampleList($samples);
        $this->assertInvocationList($samples, $sampleInvocations);

        $runnerClass = $this->runnerClassFor($runner);
        $sampleIndexes = $this->sampleIndexes($samples);
        $batchId = $this->newBatchId();
        $sampleCount = count($samples);
        $waitTimeoutSeconds = $options->waitTimeoutSeconds ?? $this->defaultWaitTimeoutSeconds;
        $resultTtlSeconds = $this->resultTtlSecondsFor($options, $waitTimeoutSeconds, $sampleCount);
        $this->startResults($batchId, $sampleCount, $resultTtlSeconds);

        // Note: rate-limit throttling deliberately does NOT apply on the
        // external dispatch-only path. Callers use dispatch() to enqueue
        // now and collect later (the documented fire-and-return flow);
        // blocking inside the producer would defeat the purpose. Workers
        // drain the queue at their own pace, and the result-store TTL
        // already accounts for the worst-case rate-limit pause time.
        try {
            foreach (array_chunk($samples, $options->effectiveChunkSize(), preserve_keys: true) as $sampleWindow) {
                $this->dispatchSampleJobs(
                    batchId: $batchId,
                    samples: $sampleWindow,
                    sampleInvocations: $sampleInvocations,
                    runnerClass: $runnerClass,
                    options: $options,
                    resultTtlSeconds: $resultTtlSeconds,
                    rateLimiter: null,
                );
            }
        } catch (Throwable $e) {
            try {
                $this->throwStoredFailureOrDispatchException(
                    batchId: $batchId,
                    sampleCount: $sampleCount,
                    indexes: $sampleIndexes,
                    previous: $e,
                );
            } catch (Throwable $primary) {
                $this->abortResultsSafely($batchId, $sampleCount, $resultTtlSeconds);

                throw $primary;
            }
        }

        return $batchId;
    }

    /**
     * @param  list<DatasetSample>  $samples
     * @return list<string>
     */
    public function collectOutputs(string $batchId, array $samples): array
    {
        $samples = $this->assertSampleList($samples);
        $storedSampleCount = $this->storedSampleCount($batchId);
        if ($storedSampleCount === null) {
            throw new EvalRunException(sprintf(
                "Lazy parallel batch '%s' result metadata is missing. Confirm the batch id is correct and the batch result cache has not expired.",
                $batchId,
            ));
        }

        $providedSampleCount = count($samples);
        if ($providedSampleCount !== $storedSampleCount) {
            throw new EvalRunException(sprintf(
                "Lazy parallel batch '%s' was initialized for %d samples; got %d samples for collection.",
                $batchId,
                $storedSampleCount,
                $providedSampleCount,
            ));
        }

        $sampleCount = $storedSampleCount;
        $resultTtlSeconds = $this->storedTtlSeconds($batchId) ?? $this->resultTtlSeconds;

        $outputsByIndex = $this->collectIndexedOutputsOrNull($batchId, $samples, $sampleCount);
        if ($outputsByIndex !== null) {
            ksort($outputsByIndex);
            $this->finishResultsSafely($batchId, $sampleCount, $resultTtlSeconds);

            return array_values($outputsByIndex);
        }

        $missingSampleIds = $this->missingSampleIds($batchId, $samples, $sampleCount);
        $outputsByIndex = $this->collectIndexedOutputsOrNull($batchId, $samples, $sampleCount);
        if ($outputsByIndex !== null) {
            ksort($outputsByIndex);
            $this->finishResultsSafely($batchId, $sampleCount, $resultTtlSeconds);

            return array_values($outputsByIndex);
        }

        throw new EvalRunException(sprintf(
            "Lazy parallel batch '%s' did not produce outputs for sample ids: %s. Confirm queue workers are running and the batch result cache is shared with workers.",
            $batchId,
            implode(', ', $missingSampleIds),
        ));
    }

    /**
     * @param  array<int, DatasetSample>  $samples
     * @return array<int, string>
     */
    private function waitForIndexedOutputs(
        string $batchId,
        array $samples,
        int $sampleCount,
        float $deadlineMicrotime,
        int $timeoutSecondsForDiagnostic,
    ): array {
        $pollIntervalMicroseconds = self::INITIAL_POLL_INTERVAL_MICROSECONDS;

        do {
            $outputs = $this->collectIndexedOutputsOrNull($batchId, $samples, $sampleCount);
            if ($outputs !== null) {
                return $outputs;
            }

            if (microtime(true) >= $deadlineMicrotime) {
                break;
            }

            $remainingMicroseconds = max(1, (int) (($deadlineMicrotime - microtime(true)) * 1_000_000));
            usleep(min($pollIntervalMicroseconds, $remainingMicroseconds));

            $pollIntervalMicroseconds = min(
                self::MAX_POLL_INTERVAL_MICROSECONDS,
                $pollIntervalMicroseconds * 2,
            );
        } while (true);

        $timeoutSeconds = $timeoutSecondsForDiagnostic;

        $failure = $this->firstFailure($batchId, $sampleCount, $this->sampleIndexes($samples));
        if ($failure !== null) {
            throw new EvalRunException(sprintf(
                "Lazy parallel batch job for sample '%s' failed: %s.",
                $failure['sample_id'],
                $failure['error'],
            ));
        }

        $missingSampleIds = $this->missingSampleIds($batchId, $samples, $sampleCount);
        $outputs = $this->collectIndexedOutputsOrNull($batchId, $samples, $sampleCount);
        if ($outputs !== null) {
            return $outputs;
        }

        $failure = $this->firstFailure($batchId, $sampleCount, $this->sampleIndexes($samples));
        if ($failure !== null) {
            throw new EvalRunException(sprintf(
                "Lazy parallel batch job for sample '%s' failed: %s.",
                $failure['sample_id'],
                $failure['error'],
            ));
        }

        throw new EvalRunException(sprintf(
            "Lazy parallel batch '%s' did not produce outputs within %s for sample ids: %s. Increase the batch wait timeout, confirm queue workers are running, and confirm the batch result cache is shared with workers.",
            $batchId,
            $this->secondsLabel($timeoutSeconds),
            implode(', ', $missingSampleIds),
        ));
    }

    /**
     * @param  list<int>  $indexes
     */
    private function throwStoredFailureOrDispatchException(string $batchId, int $sampleCount, array $indexes, Throwable $previous): never
    {
        $failure = $this->firstFailure($batchId, $sampleCount, $indexes);
        if ($failure !== null) {
            throw new EvalRunException(sprintf(
                "Lazy parallel batch job for sample '%s' failed: %s.",
                $failure['sample_id'],
                $failure['error'],
            ), previous: $previous);
        }

        throw new EvalRunException(sprintf(
            "Failed to dispatch lazy parallel batch '%s': %s.",
            $batchId,
            $previous->getMessage() !== '' ? $previous->getMessage() : $previous::class,
        ), previous: $previous);
    }

    /**
     * @param  list<int>  $indexes
     * @return array{sample_id: string, error: string}|null
     */
    private function firstFailure(string $batchId, int $sampleCount, array $indexes): ?array
    {
        $failures = $this->storedFailures($batchId, $sampleCount, $indexes);
        if ($failures === []) {
            return null;
        }

        return $failures[array_key_first($failures)];
    }

    /**
     * @param  array<int, DatasetSample>  $samples
     * @return array<int, string>|null
     */
    private function collectIndexedOutputsOrNull(string $batchId, array $samples, int $sampleCount): ?array
    {
        $indexes = $this->sampleIndexes($samples);
        $failures = $this->storedFailures($batchId, $sampleCount, $indexes);
        if ($failures !== []) {
            $firstFailure = $failures[array_key_first($failures)];

            throw new EvalRunException(sprintf(
                "Lazy parallel batch job for sample '%s' failed: %s.",
                $firstFailure['sample_id'],
                $firstFailure['error'],
            ));
        }

        $storedResults = $this->storedSuccessfulResults($batchId, $sampleCount, $indexes);
        $outputs = [];

        foreach ($samples as $index => $sample) {
            if (! array_key_exists($index, $storedResults)) {
                return null;
            }

            $result = $storedResults[$index];
            if ($result['sample_id'] !== $sample->id) {
                throw new EvalRunException(sprintf(
                    "Stored lazy parallel batch output at index %d belongs to sample '%s'; expected '%s'.",
                    $index,
                    $result['sample_id'],
                    $sample->id,
                ));
            }

            $outputs[$index] = $result['actual_output'];
        }

        return $outputs;
    }

    /**
     * @param  list<DatasetSample>  $samples
     * @param  list<mixed>  $sampleInvocations
     */
    private function assertInvocationList(array $samples, array $sampleInvocations): void
    {
        if (! array_is_list($sampleInvocations)) {
            throw new EvalRunException('Lazy parallel batch SampleInvocations must be a zero-based list.');
        }

        if (count($samples) !== count($sampleInvocations)) {
            throw new EvalRunException('Lazy parallel batch requires one SampleInvocation for every dataset sample.');
        }

        foreach ($samples as $index => $sample) {
            if (! array_key_exists($index, $sampleInvocations)) {
                throw new EvalRunException(sprintf(
                    "SampleInvocation for sample '%s' at index %d is missing.",
                    $sample->id,
                    $index,
                ));
            }

            $sampleInvocation = $sampleInvocations[$index];
            if (! $sampleInvocation instanceof SampleInvocation) {
                throw new EvalRunException(sprintf(
                    "SampleInvocation for sample '%s' at index %d must be an instance of %s; got %s.",
                    $sample->id,
                    $index,
                    SampleInvocation::class,
                    get_debug_type($sampleInvocation),
                ));
            }

            if ($sampleInvocation->id !== $sample->id) {
                throw new EvalRunException(sprintf(
                    "SampleInvocation at index %d must match dataset sample '%s'; got '%s'.",
                    $index,
                    $sample->id,
                    $sampleInvocation->id,
                ));
            }
        }
    }

    /**
     * @param  array<array-key, mixed>  $samples
     * @return list<DatasetSample>
     */
    private function assertSampleList(array $samples): array
    {
        if (! array_is_list($samples)) {
            throw new EvalRunException('Lazy parallel batch samples must be a zero-based list.');
        }

        $validated = [];
        foreach ($samples as $index => $sample) {
            if (! $sample instanceof DatasetSample) {
                throw new EvalRunException(sprintf(
                    'Lazy parallel batch sample at index %d must be an instance of %s; got %s.',
                    $index,
                    DatasetSample::class,
                    get_debug_type($sample),
                ));
            }

            $validated[] = $sample;
        }

        return $validated;
    }

    private function assertLazyParallelOptions(BatchOptions $options): void
    {
        if ($options->mode !== BatchOptions::MODE_LAZY_PARALLEL) {
            throw new EvalRunException(sprintf(
                "LazyParallelBatch requires batch mode '%s'; got '%s'.",
                BatchOptions::MODE_LAZY_PARALLEL,
                $options->mode,
            ));
        }
    }

    /**
     * @param  array<int, DatasetSample>  $samples
     * @param  list<SampleInvocation>  $sampleInvocations
     * @param  class-string<SampleRunner>  $runnerClass
     */
    private function dispatchSampleJobs(
        string $batchId,
        array $samples,
        array $sampleInvocations,
        string $runnerClass,
        BatchOptions $options,
        int $resultTtlSeconds,
        ?RateLimitWindow $rateLimiter = null,
    ): void {
        foreach ($samples as $index => $sample) {
            $this->throttleDispatch($rateLimiter);

            $sampleInvocation = $sampleInvocations[$index];
            $job = new EvaluateSampleJob(
                batchId: $batchId,
                index: $index,
                sampleId: $sample->id,
                sample: $sampleInvocation,
                runnerClass: $runnerClass,
                resultTtlSeconds: $resultTtlSeconds,
                timeoutSeconds: $options->timeoutSeconds,
            );

            if ($options->queue !== null) {
                $job->onQueue($options->queue);
            }

            $this->dispatcher->dispatch($job);

            $rateLimiter?->record(microtime(true));
        }
    }

    private function rateLimitWindow(BatchOptions $options): ?RateLimitWindow
    {
        if ($options->rateLimit === null) {
            return null;
        }

        return new RateLimitWindow(
            rateLimit: $options->rateLimit,
            rateWindowSeconds: $options->rateWindowSeconds ?? 60,
        );
    }

    private function throttleDispatch(?RateLimitWindow $rateLimiter): void
    {
        if ($rateLimiter === null) {
            return;
        }

        $waitMicroseconds = $rateLimiter->nextWaitMicroseconds(microtime(true));
        if ($waitMicroseconds > 0) {
            usleep($waitMicroseconds);
        }
    }

    private function reportCheckpointThresholdsCrossed(
        string $batchId,
        int $samplesCompleted,
        int $totalSamples,
        ?int $checkpointEvery,
        ?int $nextCheckpointThreshold,
    ): ?int {
        if ($checkpointEvery === null || $nextCheckpointThreshold === null) {
            return $nextCheckpointThreshold;
        }

        // Emit one event per multiple of N that the cumulative completed
        // count has crossed since the last call. This matters when one
        // producer window completes more than one interval at once
        // (chunkSize >= checkpointEvery), e.g. chunkSize=100 + every=25
        // => 25/50/75/100 instead of a single event at 100.
        while ($samplesCompleted >= $nextCheckpointThreshold && $nextCheckpointThreshold <= $totalSamples) {
            $this->safeReportCheckpoint($batchId, $nextCheckpointThreshold, $totalSamples);
            $nextCheckpointThreshold += $checkpointEvery;
        }

        return $nextCheckpointThreshold;
    }

    private function reportCheckpointFinalIfNeeded(
        string $batchId,
        int $samplesCompleted,
        int $totalSamples,
        ?int $checkpointEvery,
    ): void {
        if ($checkpointEvery === null) {
            return;
        }

        // Always emit a terminal end-of-batch event when checkpoint
        // reporting is enabled. Empty batches still need the event so
        // dashboards can distinguish a finished short run from a stalled
        // one. Skip only when the threshold-crossing loop already emitted
        // exactly at totalSamples (totalSamples > 0 and totalSamples is a
        // multiple of checkpointEvery).
        if ($totalSamples > 0 && $totalSamples % $checkpointEvery === 0) {
            return;
        }

        $this->safeReportCheckpoint($batchId, $samplesCompleted, $totalSamples);
    }

    private function safeReportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void
    {
        try {
            $this->progressReporter->reportCheckpoint($batchId, $samplesCompleted, $totalSamples);
        } catch (Throwable) {
            // Reporter is best-effort. A transient logging or metrics failure
            // must never abort an otherwise-healthy batch.
        }
    }

    /**
     * @return class-string<SampleRunner>
     */
    private function runnerClassFor(SampleRunner $runner): string
    {
        $runnerClass = $runner::class;
        $runnerReflection = new ReflectionClass($runnerClass);

        if (str_contains($runnerClass, "\0") || str_contains($runnerClass, '@anonymous')) {
            throw new EvalRunException(
                'Lazy parallel batch mode requires a concrete, autoloadable SampleRunner class so queue workers can resolve it.',
            );
        }

        if (! $runnerReflection->isInstantiable()) {
            throw new EvalRunException(
                'Lazy parallel batch mode requires a concrete, instantiable SampleRunner class so queue workers can resolve it.',
            );
        }

        $constructor = $runnerReflection->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($parameter->isOptional() || ! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    throw new EvalRunException(
                        'Lazy parallel batch mode requires a container-resolvable SampleRunner class; optional, untyped, or scalar constructor state from the caller instance cannot be preserved by queued workers.',
                    );
                }

            }
        }

        $initializedProperties = [];
        foreach ($runnerReflection->getProperties() as $property) {
            if ($property->isStatic() || ! $property->isInitialized($runner)) {
                continue;
            }

            $property->setAccessible(true);
            $value = $property->getValue($runner);
            if (! is_object($value)) {
                throw new EvalRunException(
                    'Lazy parallel batch mode requires a container-resolvable SampleRunner class; preconfigured runner instance state remains serial-only because queued workers resolve a fresh runner by class name.',
                );
            }

            $initializedProperties[] = $property;
        }

        if ($initializedProperties === []) {
            return $runnerClass;
        }

        if ($this->container === null) {
            throw new EvalRunException(
                'Lazy parallel batch mode requires a container-resolvable SampleRunner class; initialized runner object state can only be accepted when it matches a fresh container-resolved runner.',
            );
        }

        try {
            $resolvedRunner = $this->container->make($runnerClass);
        } catch (Throwable $e) {
            throw new EvalRunException(sprintf(
                "Lazy parallel batch mode could not resolve SampleRunner '%s' from the container: %s.",
                $runnerClass,
                $e->getMessage() !== '' ? $e->getMessage() : $e::class,
            ), previous: $e);
        }

        if (! $resolvedRunner instanceof SampleRunner) {
            throw new EvalRunException(sprintf(
                "Lazy parallel batch mode requires SampleRunner '%s' to resolve to %s; got %s.",
                $runnerClass,
                SampleRunner::class,
                get_debug_type($resolvedRunner),
            ));
        }

        if ($resolvedRunner === $runner) {
            throw new EvalRunException(
                'Lazy parallel batch mode requires the container to resolve a fresh SampleRunner instance; singleton or instance-bound runners can carry caller-specific state that workers in another process cannot preserve.',
            );
        }

        foreach ($initializedProperties as $property) {
            if (! $property->isInitialized($resolvedRunner)) {
                throw new EvalRunException(
                    'Lazy parallel batch mode requires initialized runner object state to match a fresh container-resolved runner because queued workers resolve by class name.',
                );
            }

            $runnerValue = $property->getValue($runner);
            $resolvedValue = $property->getValue($resolvedRunner);
            if (
                ! is_object($runnerValue)
                || ! is_object($resolvedValue)
                || ! $this->runnerObjectStateMatches($runnerValue, $resolvedValue)
            ) {
                throw new EvalRunException(
                    'Lazy parallel batch mode requires initialized runner object state to match a fresh container-resolved runner because queued workers resolve by class name.',
                );
            }
        }

        return $runnerClass;
    }

    private function runnerObjectStateMatches(object $runnerValue, object $resolvedValue): bool
    {
        if ($runnerValue::class !== $resolvedValue::class) {
            return false;
        }

        return $runnerValue == $resolvedValue;
    }

    /**
     * @param  array<int, DatasetSample>  $samples
     * @return list<string>
     */
    private function missingSampleIds(string $batchId, array $samples, int $sampleCount): array
    {
        $storedResults = $this->storedSuccessfulResults($batchId, $sampleCount, $this->sampleIndexes($samples));
        $missing = [];

        foreach ($samples as $index => $sample) {
            if (! array_key_exists($index, $storedResults) || $storedResults[$index]['sample_id'] !== $sample->id) {
                $missing[] = $sample->id;
            }
        }

        return $missing;
    }

    /**
     * @param  array<int, DatasetSample>  $samples
     * @return list<int>
     */
    private function sampleIndexes(array $samples): array
    {
        $indexes = [];
        foreach ($samples as $index => $_sample) {
            if (! is_int($index)) {
                throw new EvalRunException(sprintf(
                    'Lazy parallel batch sample indexes must be integers; got %s.',
                    get_debug_type($index),
                ));
            }

            $indexes[] = $index;
        }

        return $indexes;
    }

    private function storedSampleCount(string $batchId): ?int
    {
        return $this->withResultStore(
            action: 'read metadata from',
            batchId: $batchId,
            callback: fn (): ?int => $this->resultStore->sampleCount($batchId),
        );
    }

    private function storedTtlSeconds(string $batchId): ?int
    {
        return $this->withResultStore(
            action: 'read metadata from',
            batchId: $batchId,
            callback: fn (): ?int => $this->resultStore->ttlSeconds($batchId),
        );
    }

    private function resultTtlSecondsFor(BatchOptions $options, int $waitTimeoutSeconds, ?int $sampleCount = null): int
    {
        $windowWaitSeconds = 0;
        $rateLimitSeconds = 0;
        if ($sampleCount !== null) {
            // Window count must mirror the effective producer window so the TTL
            // covers the actual loop. When --chunk-size is smaller than
            // --concurrency, the loop now waits across many more windows than
            // a concurrency-based estimate would account for.
            $effectiveChunkSize = $options->effectiveChunkSize();
            $windowCount = max(1, intdiv($sampleCount + $effectiveChunkSize - 1, $effectiveChunkSize));
            $windowWaitSeconds = max($waitTimeoutSeconds, $options->timeoutSeconds ?? 0) * $windowCount;

            // Producer-side rate limiting also adds wall-clock delay before
            // later samples are even enqueued. The worst case is a full
            // rate-window pause between every full burst of `rateLimit`
            // dispatches, so the TTL must cover dispatch + worker time
            // together.
            if ($options->rateLimit !== null) {
                $rateWindow = $options->rateWindowSeconds ?? 60;
                $rateBatches = max(1, intdiv($sampleCount + $options->rateLimit - 1, $options->rateLimit));
                $rateLimitSeconds = ($rateBatches - 1) * $rateWindow;
            }
        }

        return max(
            $this->resultTtlSeconds,
            $waitTimeoutSeconds,
            $windowWaitSeconds + $rateLimitSeconds,
            $options->timeoutSeconds ?? 0,
            $options->resultTtlSeconds ?? 0,
        );
    }

    private function startResults(string $batchId, int $sampleCount, int $resultTtlSeconds): void
    {
        $this->withResultStore(
            action: 'initialize',
            batchId: $batchId,
            callback: function () use ($batchId, $sampleCount, $resultTtlSeconds): bool {
                $this->resultStore->start($batchId, $sampleCount, $resultTtlSeconds);

                return true;
            },
        );
    }

    private function finishResultsSafely(string $batchId, int $sampleCount, int $resultTtlSeconds): void
    {
        $this->cleanupResultsSafely('finish', $batchId, $sampleCount, $resultTtlSeconds);
    }

    private function abortResultsSafely(string $batchId, int $sampleCount, int $resultTtlSeconds): void
    {
        $this->cleanupResultsSafely('abort', $batchId, $sampleCount, $resultTtlSeconds);
    }

    /**
     * @param  list<int>  $indexes
     * @return array<int, array{sample_id: string, error: string}>
     */
    private function storedFailures(string $batchId, int $sampleCount, array $indexes): array
    {
        return $this->withResultStore(
            action: 'read failures from',
            batchId: $batchId,
            callback: fn (): array => $this->resultStore->failures($batchId, $sampleCount, $indexes),
        );
    }

    /**
     * @param  list<int>  $indexes
     * @return array<int, array{sample_id: string, actual_output: string}>
     */
    private function storedSuccessfulResults(string $batchId, int $sampleCount, array $indexes): array
    {
        return $this->withResultStore(
            action: 'read outputs from',
            batchId: $batchId,
            callback: fn (): array => $this->resultStore->successfulResults($batchId, $sampleCount, $indexes),
        );
    }

    private function cleanupResultsSafely(string $action, string $batchId, int $sampleCount, int $resultTtlSeconds): void
    {
        try {
            if ($action === 'finish') {
                $this->resultStore->finish($batchId, $sampleCount, $resultTtlSeconds);

                return;
            }

            $this->resultStore->abort($batchId, $sampleCount, $resultTtlSeconds);
        } catch (Throwable) {
            // Cleanup is best-effort; it must not mask the run, dispatch, or timeout outcome.
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withResultStore(string $action, string $batchId, callable $callback): mixed
    {
        try {
            return $callback();
        } catch (EvalRunException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new EvalRunException(sprintf(
                "Failed to %s lazy parallel batch result store for batch '%s': %s.",
                $action,
                $batchId,
                $e->getMessage() !== '' ? $e->getMessage() : $e::class,
            ), previous: $e);
        }
    }

    private function newBatchId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (RandomException $e) {
            throw new EvalRunException('Failed to generate a lazy parallel batch id.', previous: $e);
        }
    }

    private function secondsLabel(int $seconds): string
    {
        return sprintf('%d %s', $seconds, $seconds === 1 ? 'second' : 'seconds');
    }
}
