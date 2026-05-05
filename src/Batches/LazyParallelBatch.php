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

    /**
     * Fallback per-drain-batch runtime in seconds for the
     * dispatch() TTL math when `BatchOptions::timeoutSeconds`
     * is null. Intentionally a fixed constant (matches typical
     * queue worker default) rather than a config-driven value,
     * so the dispatch path's TTL stays decoupled from
     * `eval-harness.batches.lazy_parallel.wait_timeout_seconds`.
     */
    private const DISPATCH_PER_DRAIN_BATCH_FALLBACK_SECONDS = 60;

    /**
     * Monotonic seconds for deadline / throttle math.
     *
     * `microtime(true)` follows wall-clock time and can step
     * backwards on NTP correction or VM clock jumps. That breaks
     * throttle and deadline math: a backward step makes the
     * producer fail a chunk early ("deadline reached" when no time
     * passed), and a forward step makes the producer sleep far
     * longer than the configured `--batch-timeout`. `hrtime(true)`
     * returns a monotonic nanosecond counter from an unspecified
     * epoch (system boot on Linux/macOS) — only deltas are
     * meaningful, but that is exactly what deadline / rate-window
     * math consumes.
     */
    private function monotonicTime(): float
    {
        return hrtime(true) / 1_000_000_000.0;
    }

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
        $resultTtlSeconds = $this->resultTtlSecondsForRun($options, $waitTimeoutSeconds, $sampleCount);
        $completed = false;

        $rateLimiter = $this->rateLimitWindow($options);
        $checkpointEvery = $options->checkpointEvery;
        $samplesCompleted = 0;
        $nextCheckpointThreshold = $checkpointEvery;

        try {
            // Initialize the result store inside the try so a cache
            // outage at startup still emits STATUS_FAILURE to
            // dashboards. Without this, an unavailable result
            // store would surface as a silent stall (no SUCCESS,
            // no FAILURE) even though a batch id was already
            // allocated and the run is unmistakably broken.
            $this->startResults($batchId, $sampleCount, $resultTtlSeconds);

            foreach (array_chunk($samples, $options->effectiveChunkSize(), preserve_keys: true) as $sampleWindow) {
                // The chunk deadline covers BOTH dispatch (which can
                // include rate-limit pauses) and result collection so
                // the wait timeout always bounds the per-window wall
                // clock. Without this, a low rateLimit with a large
                // chunk could spend most of the operator-supplied
                // budget sleeping inside dispatchSampleJobs() before
                // collection started.
                $chunkDeadline = $this->monotonicTime() + $waitTimeoutSeconds;
                $dispatchedInWindow = 0;

                try {
                    $dispatchedInWindow = $this->dispatchSampleJobs(
                        batchId: $batchId,
                        samples: $sampleWindow,
                        sampleInvocations: $sampleInvocations,
                        runnerClass: $runnerClass,
                        options: $options,
                        resultTtlSeconds: $resultTtlSeconds,
                        rateLimiter: $rateLimiter,
                        chunkDeadlineMonotonicSeconds: $chunkDeadline,
                    );
                } catch (Throwable $e) {
                    $this->throwStoredFailureOrDispatchException(
                        batchId: $batchId,
                        sampleCount: $sampleCount,
                        indexes: $this->sampleIndexes($sampleWindow),
                        previous: $e,
                    );
                }

                $undispatched = count($sampleWindow) - $dispatchedInWindow;
                // Only treat the deadline as exceeded when dispatch was
                // actually aborted mid-window. With fast workers or the
                // sync queue driver, every sample can finish inside the
                // budget and still cross the deadline by a few
                // microseconds during the post-loop microtime() call;
                // in that case `dispatchedInWindow == count($window)`
                // and the right thing to do is collect the outputs
                // that are already there, not flip a healthy run to
                // a `0 of N undispatched` false failure.
                if ($undispatched > 0 && $this->monotonicTime() >= $chunkDeadline) {
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

                    // Report the count of UNDISPATCHED samples, not the
                    // full chunk: when the deadline fires mid-window
                    // only the remainder were actually blocked by the
                    // timeout. Operators tuning chunk size / rate
                    // limits need that distinction.
                    // Diagnostic stays neutral so library callers using
                    // EvalEngine::runBatch() / runEvalSet() do not get
                    // CLI-only remediation guidance.
                    throw new EvalRunException(sprintf(
                        "Lazy parallel batch '%s' chunk dispatch consumed the full %s wait timeout with %d of %d sample(s) still undispatched before result collection started; lower the chunk size, relax the rate limit, or raise the wait timeout.",
                        $batchId,
                        $this->secondsLabel($waitTimeoutSeconds),
                        $undispatched,
                        count($sampleWindow),
                    ));
                }

                $priorWindowsCompleted = $samplesCompleted;
                // Only wire mid-chunk progress reporting when the
                // operator actually enabled checkpointing. Otherwise
                // every poll iteration would do an extra
                // O(chunkSize) result-store scan for no user-visible
                // benefit, materially increasing cache traffic on
                // large windows.
                $onProgress = $checkpointEvery === null
                    ? null
                    : function (int $windowSuccesses) use (
                        $batchId,
                        $priorWindowsCompleted,
                        $sampleCount,
                        $checkpointEvery,
                        &$nextCheckpointThreshold,
                    ): void {
                        $running = min($sampleCount, $priorWindowsCompleted + $windowSuccesses);
                        $nextCheckpointThreshold = $this->reportCheckpointThresholdsCrossed(
                            batchId: $batchId,
                            samplesCompleted: $running,
                            totalSamples: $sampleCount,
                            checkpointEvery: $checkpointEvery,
                            nextCheckpointThreshold: $nextCheckpointThreshold,
                        );
                    };

                $outputsByIndex += $this->waitForIndexedOutputs(
                    batchId: $batchId,
                    samples: $sampleWindow,
                    sampleCount: $sampleCount,
                    deadlineMonotonicSeconds: $chunkDeadline,
                    timeoutSecondsForDiagnostic: $waitTimeoutSeconds,
                    onProgress: $onProgress,
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

            $this->reportCheckpointFinalIfNeeded(
                batchId: $batchId,
                samplesCompleted: $samplesCompleted,
                totalSamples: $sampleCount,
                checkpointEvery: $checkpointEvery,
            );

            // Emit terminal SUCCESS / EMPTY only after output
            // assembly + validation has succeeded. If we emitted
            // earlier and the validation loop above threw (missing
            // index, mismatched stored output), dashboards would
            // receive STATUS_SUCCESS followed by STATUS_FAILURE for
            // the same batch — contradictory terminal states.
            $this->safeReportTerminal(
                batchId: $batchId,
                samplesCompleted: $samplesCompleted,
                totalSamples: $sampleCount,
                status: $sampleCount === 0
                    ? BatchTerminalProgressReporter::STATUS_EMPTY
                    : BatchTerminalProgressReporter::STATUS_SUCCESS,
            );

            $completed = true;
            $this->finishResultsSafely($batchId, $sampleCount, $resultTtlSeconds);

            return $outputs;
        } catch (Throwable $e) {
            if (! $completed) {
                // Failure path emits a forced terminal checkpoint
                // (legacy reporters) AND an explicit STATUS_FAILURE
                // terminal event (reporters on the new contract).
                //
                // samplesCompleted is the per-window counter — only
                // updated AFTER waitForIndexedOutputs() returns. When
                // a window fails mid-collection, we add the count of
                // stored successes WITHIN THAT WINDOW so partial wins
                // are reflected in the dashboard event. The cost is
                // O(chunkSize), not O(sampleCount), so the failure
                // path stays cheap even on large batches.
                //
                // safeReport* helpers swallow reporter exceptions so
                // this never masks the original failure.
                $partialWindowWins = $this->countWindowSuccessesSafely(
                    batchId: $batchId,
                    sampleCount: $sampleCount,
                    sampleWindow: $sampleWindow ?? null,
                    samplesCompleted: $samplesCompleted,
                );
                $reportedCompleted = min($sampleCount, $samplesCompleted + $partialWindowWins);

                $this->reportCheckpointTerminalForce(
                    batchId: $batchId,
                    samplesCompleted: $reportedCompleted,
                    totalSamples: $sampleCount,
                    checkpointEvery: $checkpointEvery,
                );
                $this->safeReportTerminal(
                    batchId: $batchId,
                    samplesCompleted: $reportedCompleted,
                    totalSamples: $sampleCount,
                    status: BatchTerminalProgressReporter::STATUS_FAILURE,
                );
                $this->abortResultsSafely($batchId, $sampleCount, $resultTtlSeconds);
            }

            throw $e;
        }
    }

    /**
     * Best-effort count of stored successes within the failed window.
     *
     * Bounded by chunk size (the window length), not sampleCount, so
     * the failure path stays O(chunkSize) instead of O(sampleCount).
     * Returns 0 when:
     *   - No window has been entered yet (empty batch / pre-loop failure).
     *   - All windows already completed (post-loop assembly failure;
     *     samplesCompleted already reflects everything).
     *   - The result store query fails for any reason — we swallow the
     *     exception so the original failure can propagate cleanly.
     *
     * @param  array<int, DatasetSample>|null  $sampleWindow
     */
    private function countWindowSuccessesSafely(
        string $batchId,
        int $sampleCount,
        ?array $sampleWindow,
        int $samplesCompleted,
    ): int {
        if ($sampleWindow === null || $samplesCompleted >= $sampleCount) {
            return 0;
        }

        try {
            $windowIndexes = $this->sampleIndexes($sampleWindow);

            return count($this->storedSuccessfulResults($batchId, $sampleCount, $windowIndexes));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Dispatch every sample job and return the opaque batch id for later collection.
     *
     * This method intentionally does not wait between concurrency windows; it is
     * useful for Queue::fake() assertions and external schedulers. Engine runs
     * should use run(), which applies the concurrency window before collecting.
     *
     * Fire-and-return semantics hold ONLY on real queue drivers (Redis,
     * database, beanstalk — the documented Horizon path) where
     * `dispatcher->dispatch()` enqueues the job and returns immediately.
     * On Laravel's `sync` driver, dispatch executes each job INLINE in
     * this method, so dispatch() blocks for the full sample runtime per
     * sample. Callers using `sync` (typically tests and quick scripts)
     * should size TTLs and execution flow against worst-case synchronous
     * runner time, not against the abstract fire-and-return contract.
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
        // dispatch() is fire-and-return: callers enqueue now and
        // collect later. TTL math drops the producer-side rate-limit
        // pause (run-only) AND the chunkSize-based window factor AND
        // the operator's --batch-timeout (it bounds run()'s producer
        // wait, not worker drain time). Operators with large batches
        // or constrained worker pools should override the floor via
        // BatchOptions::lazyParallel(resultTtlSeconds: ...).
        $resultTtlSeconds = $this->resultTtlSecondsForDispatch($options, $sampleCount);
        $this->startResults($batchId, $sampleCount, $resultTtlSeconds);

        // Note: rate-limit throttling deliberately does NOT apply on the
        // external dispatch-only path. Callers use dispatch() to enqueue
        // now and collect later (the documented fire-and-return flow on
        // real queue drivers — Redis / database / beanstalk / Horizon);
        // blocking inside the producer would defeat the purpose. Workers
        // drain the queue at their own pace.
        //
        // Sync queue caveat: with Laravel's `sync` driver,
        // `dispatcher->dispatch()` runs each job INLINE inside this
        // loop, so dispatch() effectively blocks for the full sample
        // runtime per sample. The TTL math still skips the producer-
        // side rate-limit pause (this method does not throttle) but
        // callers using `sync` for tests should size their own
        // execution flow / TTL expectations against the worst-case
        // synchronous runner time.
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
     * @param  (callable(int): void)|null  $onProgress  Called after each poll
     *                                                  with the cumulative count of stored successes inside this
     *                                                  window so the caller can emit mid-chunk progress checkpoints
     *                                                  as workers finish samples — without waiting for the entire
     *                                                  chunk to land.
     * @return array<int, string>
     */
    private function waitForIndexedOutputs(
        string $batchId,
        array $samples,
        int $sampleCount,
        float $deadlineMonotonicSeconds,
        int $timeoutSecondsForDiagnostic,
        ?callable $onProgress = null,
    ): array {
        $pollIntervalMicroseconds = self::INITIAL_POLL_INTERVAL_MICROSECONDS;
        $lastReportedWindowSuccesses = 0;
        $windowIndexes = $this->sampleIndexes($samples);

        do {
            // Emit intermediate progress BEFORE checking for failure
            // outputs. collectIndexedOutputsOrNull() throws when it
            // sees a stored failure, so if we deferred the progress
            // probe until after that, a poll that found multiple new
            // successes alongside a failure (e.g. 5 and 10 succeeded
            // before 14 threw) would skip the intermediate
            // checkpoint thresholds and only emit the failure-path
            // forced terminal checkpoint at reportedCompleted —
            // breaking the documented "one checkpoint per crossed
            // multiple" contract on failed windows.
            if ($onProgress !== null) {
                // Best-effort partial-progress count. Bounded by
                // chunk size, not sampleCount, so the cost stays
                // cheap. If the result store query throws, swallow
                // it so the poll loop is not aborted by transient
                // cache trouble.
                try {
                    $current = count($this->storedSuccessfulResults($batchId, $sampleCount, $windowIndexes));
                } catch (Throwable) {
                    $current = $lastReportedWindowSuccesses;
                }
                if ($current !== $lastReportedWindowSuccesses) {
                    $lastReportedWindowSuccesses = $current;
                    $onProgress($current);
                }
            }

            $outputs = $this->collectIndexedOutputsOrNull($batchId, $samples, $sampleCount);
            if ($outputs !== null) {
                return $outputs;
            }

            if ($this->monotonicTime() >= $deadlineMonotonicSeconds) {
                break;
            }

            $remainingMicroseconds = max(1, (int) (($deadlineMonotonicSeconds - $this->monotonicTime()) * 1_000_000));
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
     * @return int Number of samples actually dispatched. Lower than
     *             count($samples) when the chunk deadline aborted the
     *             loop mid-window. Used by the deadline-exceeded
     *             diagnostic so it reports the count of UNDISPATCHED
     *             samples instead of the full chunk size — operators
     *             tuning chunk size / rate limits need to know how
     *             many samples were actually still blocked when the
     *             timeout fired.
     */
    private function dispatchSampleJobs(
        string $batchId,
        array $samples,
        array $sampleInvocations,
        string $runnerClass,
        BatchOptions $options,
        int $resultTtlSeconds,
        ?RateLimitWindow $rateLimiter = null,
        ?float $chunkDeadlineMonotonicSeconds = null,
    ): int {
        $dispatched = 0;
        foreach ($samples as $index => $sample) {
            // Stop dispatching when the chunk deadline has been
            // reached. Without this check, throttleDispatch() could
            // keep sleeping through many rate windows past
            // --batch-timeout, breaking the documented hard wall-clock
            // cap on the producer window. The caller will surface a
            // stored failure first or the deadline-exceeded error.
            if ($chunkDeadlineMonotonicSeconds !== null && $this->monotonicTime() >= $chunkDeadlineMonotonicSeconds) {
                return $dispatched;
            }

            $deadlineCapped = $this->throttleDispatch($rateLimiter, $chunkDeadlineMonotonicSeconds);

            if ($chunkDeadlineMonotonicSeconds !== null && $this->monotonicTime() >= $chunkDeadlineMonotonicSeconds) {
                // throttleDispatch() may have woken early at the
                // deadline; bail before recording a dispatch we did
                // not actually make.
                return $dispatched;
            }

            // Post-sleep rate-limiter recheck — but only when the
            // throttle wait was actually capped by the chunk
            // deadline. Without the cap, throttleDispatch() honoured
            // the full required wait and the limiter has capacity by
            // construction; an extra recheck would reject the
            // dispatch on harmless `usleep()` timer slop. With the
            // cap, the wait was shortened and the rate window may
            // not actually be open yet — bail cleanly so a tight
            // `--batch-timeout` cannot let one extra sample bypass
            // the configured rate limit.
            if ($deadlineCapped && $rateLimiter !== null && $rateLimiter->nextWaitMicroseconds($this->monotonicTime()) > 0) {
                // Park until the deadline so the caller's deadline
                // check fires consistently. Without this, a usleep
                // that wakes a few microseconds early would leave
                // monotonicTime() < chunkDeadline at the caller's
                // check, the deadline-exceeded path would be
                // skipped, and waitForIndexedOutputs() would surface
                // a misleading "did not produce outputs" diagnostic
                // instead of the documented chunk-dispatch-consumed
                // message — even though by recheck we already know
                // the rate window will not reopen before the
                // deadline.
                if ($chunkDeadlineMonotonicSeconds !== null) {
                    $remainingMicroseconds = (int) (($chunkDeadlineMonotonicSeconds - $this->monotonicTime()) * 1_000_000);
                    if ($remainingMicroseconds > 0) {
                        usleep($remainingMicroseconds);
                    }
                }

                return $dispatched;
            }

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

            // Record dispatch timestamp BEFORE the dispatcher actually
            // hands off the job. On the sync queue driver, dispatch()
            // runs the job synchronously, so recording afterwards would
            // capture completion time instead of dispatch time and a
            // slow sample would stretch the next throttle wait by its
            // own runtime.
            //
            // Wall-clock cap caveat: the chunk deadline is a hard cap
            // ONLY when `dispatcher->dispatch()` returns immediately
            // (Redis, database, beanstalk drivers — i.e. the documented
            // Horizon path). On the `sync` queue driver dispatch
            // executes the job INLINE, so a single slow sample can run
            // arbitrarily longer than the chunk deadline before control
            // returns and the deadline check fires. The package only
            // sets the queue job's `$timeout` property, which is
            // honoured by real queue workers but NOT enforced by the
            // sync driver — so on sync neither `--batch-timeout` nor
            // `--timeout` bound per-sample runtime. Operators that need
            // any wall-clock guarantee should use a real queue driver
            // in production.
            $rateLimiter?->record($this->monotonicTime());

            $this->dispatcher->dispatch($job);
            $dispatched++;
        }

        return $dispatched;
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

    /**
     * @return bool True when the throttle wait was capped by the chunk
     *              deadline (i.e. the rate-window may not actually be
     *              open yet on wake-up — the caller must recheck before
     *              dispatching). False when no wait happened or the
     *              full required wait was honoured.
     */
    private function throttleDispatch(?RateLimitWindow $rateLimiter, ?float $deadlineMonotonicSeconds = null): bool
    {
        if ($rateLimiter === null) {
            return false;
        }

        $waitMicroseconds = $rateLimiter->nextWaitMicroseconds($this->monotonicTime());
        if ($waitMicroseconds <= 0) {
            return false;
        }

        // Cap the throttle pause at the chunk deadline so the producer
        // window cannot overshoot --batch-timeout while sleeping on
        // a rate-limit pause. The caller will detect the deadline
        // afterwards and bail before recording a dispatch.
        $deadlineCapped = false;
        if ($deadlineMonotonicSeconds !== null) {
            $remainingMicroseconds = (int) (($deadlineMonotonicSeconds - $this->monotonicTime()) * 1_000_000);
            if ($remainingMicroseconds <= 0) {
                return true;
            }
            if ($remainingMicroseconds < $waitMicroseconds) {
                $waitMicroseconds = $remainingMicroseconds;
                $deadlineCapped = true;
            }
        }

        usleep($waitMicroseconds);

        return $deadlineCapped;
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
     * Best-effort terminal event with explicit status for reporters
     * that implement {@see BatchTerminalProgressReporter}.
     *
     * Reporters still on the legacy bare-checkpoint contract receive
     * NO terminal-equivalent emission from this method (they only
     * see the regular `reportCheckpoint()` events emitted at the
     * configured `checkpointEvery` interval, plus the forced final
     * checkpoint from `reportCheckpointTerminalForce()` on the
     * failure path — and only when `checkpointEvery !== null`).
     * Operators wiring dashboards that need a guaranteed end-of-batch
     * signal must implement `BatchTerminalProgressReporter`.
     */
    private function safeReportTerminal(
        string $batchId,
        int $samplesCompleted,
        int $totalSamples,
        string $status,
    ): void {
        if (! $this->progressReporter instanceof BatchTerminalProgressReporter) {
            return;
        }

        try {
            $this->progressReporter->reportTerminal($batchId, $samplesCompleted, $totalSamples, $status);
        } catch (Throwable) {
            // Reporter best-effort.
        }
    }

    /**
     * Forced terminal checkpoint for the failure path.
     *
     * Unlike `reportCheckpointFinalIfNeeded`, this always emits when
     * checkpoint reporting is configured. The success path uses the
     * guarded helper to avoid duplicating an emission the threshold
     * loop already produced at totalSamples; the failure path needs
     * an unconditional terminal event so dashboards can distinguish a
     * finished failed batch from a stalled one even when totalSamples
     * is an exact multiple of checkpointEvery.
     */
    private function reportCheckpointTerminalForce(
        string $batchId,
        int $samplesCompleted,
        int $totalSamples,
        ?int $checkpointEvery,
    ): void {
        if ($checkpointEvery === null) {
            return;
        }

        $this->safeReportCheckpoint($batchId, $samplesCompleted, $totalSamples);
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

    /**
     * Compute the result-store TTL for a run() batch.
     *
     * run() waits between producer windows; rate-limit throttle time
     * is bounded by the per-window chunk deadline, so it is already
     * included in `windowWaitSeconds`. Adding it as a separate term
     * would double-count and over-retain by minutes-to-hours on
     * heavily throttled runs. The caller-side floor (default 3600s,
     * configurable per batch) and per-job timeout still apply.
     */
    private function resultTtlSecondsForRun(BatchOptions $options, int $waitTimeoutSeconds, int $sampleCount): int
    {
        // Window count must mirror the effective producer window so
        // the TTL covers the actual loop. When --chunk-size is smaller
        // than --concurrency, the loop waits across many more windows
        // than a concurrency-based estimate would account for.
        $effectiveChunkSize = $options->effectiveChunkSize();
        $windowCount = max(1, intdiv($sampleCount + $effectiveChunkSize - 1, $effectiveChunkSize));
        $windowWaitSeconds = max($waitTimeoutSeconds, $options->timeoutSeconds ?? 0) * $windowCount;

        return max(
            $this->resultTtlSeconds,
            $waitTimeoutSeconds,
            $windowWaitSeconds,
            $options->timeoutSeconds ?? 0,
            $options->resultTtlSeconds ?? 0,
        );
    }

    /**
     * Compute the result-store TTL for a dispatch()-only batch.
     *
     * dispatch() is fire-and-return: it does not throttle and does
     * not wait between producer windows, so chunkSize and the
     * producer-side rate-limit pause time MUST NOT factor into the
     * TTL. Neither does `waitTimeoutSeconds` (`--batch-timeout`):
     * it is the producer-side wait budget for `run()` and has no
     * effect on worker drain time on the dispatch() path. Including
     * it would inflate cache TTL by hours or days whenever callers
     * raise `--batch-timeout`.
     *
     * The harness still has to keep result metadata alive long
     * enough for `collectOutputs()` to read it back, so the TTL
     * must reflect worker-side drain time. Worker pool capacity is
     * unknown to the harness; concurrency (the producer fan-out
     * cap) is used as the proxy and total drain time is estimated
     * as ceil(sampleCount / concurrency) windows of `--timeout`.
     *
     * Pool-mismatch caveat: Horizon `maxProcesses` can legitimately
     * be SMALLER than `--concurrency` (e.g. running 4 workers
     * against `--concurrency=20` to bound provider QPS). In that
     * setup the actual drain time is
     * `ceil(sampleCount / maxProcesses) * --timeout` — larger than
     * what this estimate produces — so result metadata can expire
     * before delayed `collectOutputs()` finishes. Operators in the
     * pool-mismatch case (and operators with substantially LARGER
     * pools who want a tighter TTL) MUST override the floor
     * explicitly via `BatchOptions::lazyParallel(resultTtlSeconds:
     * ...)`. The harness has no portable way to introspect Horizon
     * worker counts so this is a conscious operator hand-off.
     */
    private function resultTtlSecondsForDispatch(BatchOptions $options, int $sampleCount): int
    {
        $drainBatches = max(1, intdiv($sampleCount + $options->concurrency - 1, $options->concurrency));
        // When `--timeout` is null (the documented default — callers
        // rely on the queue worker's own timeout), fall back to a
        // fixed per-drain-batch floor (60s, matching the typical
        // queue worker default) so drainSeconds is non-zero on
        // large batches.
        //
        // The fallback is INTENTIONALLY a fixed constant rather
        // than the constructor's `defaultWaitTimeoutSeconds`. Tying
        // it to the wait-timeout config would re-couple the
        // dispatch path's TTL to `eval-harness.batches.lazy_parallel
        // .wait_timeout_seconds` — operators raising the producer-
        // side wait timeout for `run()` would then also inflate
        // dispatch-path cache retention, which this fallback
        // explicitly aims to avoid. Operators with longer-running
        // jobs should set `timeoutSeconds` (or
        // `BatchOptions::lazyParallel(resultTtlSeconds: ...)`) to
        // get a larger drain window.
        $perDrainBatchSeconds = $options->timeoutSeconds ?? self::DISPATCH_PER_DRAIN_BATCH_FALLBACK_SECONDS;
        $drainSeconds = $drainBatches * $perDrainBatchSeconds;

        return max(
            $this->resultTtlSeconds,
            $drainSeconds,
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
