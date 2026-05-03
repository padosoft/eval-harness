<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

use Illuminate\Contracts\Bus\Dispatcher;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Jobs\EvaluateSampleJob;
use Random\RandomException;
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
        private readonly int $resultTtlSeconds = self::DEFAULT_RESULT_TTL_SECONDS,
        private readonly int $defaultWaitTimeoutSeconds = self::DEFAULT_WAIT_TIMEOUT_SECONDS,
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
        $this->assertInvocationList($samples, $sampleInvocations);

        $runnerClass = $this->runnerClassFor($runner);
        $batchId = $this->newBatchId();
        $sampleCount = count($samples);
        $outputsByIndex = [];
        $waitTimeoutSeconds = $options->waitTimeoutSeconds ?? $this->defaultWaitTimeoutSeconds;
        $completed = false;

        $this->resultStore->start($batchId, $sampleCount, $this->resultTtlSeconds);

        try {
            foreach (array_chunk($samples, $options->concurrency, preserve_keys: true) as $sampleWindow) {
                try {
                    $this->dispatchSampleJobs(
                        batchId: $batchId,
                        samples: $sampleWindow,
                        sampleInvocations: $sampleInvocations,
                        runnerClass: $runnerClass,
                        options: $options,
                    );
                } catch (Throwable $e) {
                    $this->throwStoredFailureOrDispatchException(
                        batchId: $batchId,
                        sampleCount: $sampleCount,
                        indexes: $this->sampleIndexes($sampleWindow),
                        previous: $e,
                    );
                }

                $outputsByIndex += $this->waitForIndexedOutputs(
                    batchId: $batchId,
                    samples: $sampleWindow,
                    sampleCount: $sampleCount,
                    timeoutSeconds: $waitTimeoutSeconds,
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

            $completed = true;

            return $outputs;
        } finally {
            if ($completed) {
                $this->resultStore->finish($batchId, $sampleCount, $this->resultTtlSeconds);
            } else {
                $this->resultStore->abort($batchId, $sampleCount, $this->resultTtlSeconds);
            }
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
        $this->assertInvocationList($samples, $sampleInvocations);

        $runnerClass = $this->runnerClassFor($runner);
        $batchId = $this->newBatchId();
        $sampleCount = count($samples);
        $this->resultStore->start($batchId, $sampleCount, $this->resultTtlSeconds);
        $currentIndexes = $this->sampleIndexes($samples);

        try {
            foreach (array_chunk($samples, $options->concurrency, preserve_keys: true) as $sampleWindow) {
                $currentIndexes = $this->sampleIndexes($sampleWindow);

                $this->dispatchSampleJobs(
                    batchId: $batchId,
                    samples: $sampleWindow,
                    sampleInvocations: $sampleInvocations,
                    runnerClass: $runnerClass,
                    options: $options,
                );
            }
        } catch (Throwable $e) {
            try {
                $this->throwStoredFailureOrDispatchException(
                    batchId: $batchId,
                    sampleCount: $sampleCount,
                    indexes: $currentIndexes,
                    previous: $e,
                );
            } finally {
                $this->resultStore->abort($batchId, $sampleCount, $this->resultTtlSeconds);
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
        $sampleCount = count($samples);

        try {
            $outputsByIndex = $this->collectIndexedOutputsOrNull($batchId, $samples, $sampleCount);
            if ($outputsByIndex !== null) {
                ksort($outputsByIndex);
                $this->resultStore->finish($batchId, $sampleCount, $this->resultTtlSeconds);

                return array_values($outputsByIndex);
            }
        } catch (Throwable $e) {
            $this->resultStore->abort($batchId, $sampleCount, $this->resultTtlSeconds);

            throw $e;
        }

        $missingSampleIds = $this->missingSampleIds($batchId, $samples, $sampleCount);

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
    private function waitForIndexedOutputs(string $batchId, array $samples, int $sampleCount, int $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $pollIntervalMicroseconds = self::INITIAL_POLL_INTERVAL_MICROSECONDS;

        do {
            $outputs = $this->collectIndexedOutputsOrNull($batchId, $samples, $sampleCount);
            if ($outputs !== null) {
                return $outputs;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            $remainingMicroseconds = max(1, (int) (($deadline - microtime(true)) * 1_000_000));
            usleep(min($pollIntervalMicroseconds, $remainingMicroseconds));

            $pollIntervalMicroseconds = min(
                self::MAX_POLL_INTERVAL_MICROSECONDS,
                $pollIntervalMicroseconds * 2,
            );
        } while (true);

        $missingSampleIds = $this->missingSampleIds($batchId, $samples, $sampleCount);

        throw new EvalRunException(sprintf(
            "Lazy parallel batch '%s' did not produce outputs for sample ids: %s. Confirm queue workers are running and the batch result cache is shared with workers.",
            $batchId,
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
        $failures = $this->resultStore->failures($batchId, $sampleCount, $indexes);
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
        $failures = $this->resultStore->failures($batchId, $sampleCount, $indexes);
        if ($failures !== []) {
            $firstFailure = $failures[array_key_first($failures)];

            throw new EvalRunException(sprintf(
                "Lazy parallel batch job for sample '%s' failed: %s.",
                $firstFailure['sample_id'],
                $firstFailure['error'],
            ));
        }

        $storedOutputs = $this->resultStore->successfulOutputs($batchId, $sampleCount, $indexes);
        $outputs = [];

        foreach ($samples as $index => $_sample) {
            if (! array_key_exists($index, $storedOutputs)) {
                return null;
            }

            $outputs[$index] = $storedOutputs[$index];
        }

        return $outputs;
    }

    /**
     * @param  list<DatasetSample>  $samples
     * @param  list<SampleInvocation>  $sampleInvocations
     */
    private function assertInvocationList(array $samples, array $sampleInvocations): void
    {
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
    ): void {
        foreach ($samples as $index => $sample) {
            $sampleInvocation = $sampleInvocations[$index];
            $job = new EvaluateSampleJob(
                batchId: $batchId,
                index: $index,
                sampleId: $sample->id,
                sample: $sampleInvocation,
                runnerClass: $runnerClass,
                resultTtlSeconds: $this->resultTtlSeconds,
                timeoutSeconds: $options->timeoutSeconds,
            );

            if ($options->queue !== null) {
                $job->onQueue($options->queue);
            }

            $this->dispatcher->dispatch($job);
        }
    }

    /**
     * @return class-string<SampleRunner>
     */
    private function runnerClassFor(SampleRunner $runner): string
    {
        $runnerClass = $runner::class;

        if (str_contains($runnerClass, "\0") || str_contains($runnerClass, '@anonymous')) {
            throw new EvalRunException(
                'Lazy parallel batch mode requires a concrete, autoloadable SampleRunner class so queue workers can resolve it.',
            );
        }

        return $runnerClass;
    }

    /**
     * @param  array<int, DatasetSample>  $samples
     * @return list<string>
     */
    private function missingSampleIds(string $batchId, array $samples, int $sampleCount): array
    {
        $storedOutputs = $this->resultStore->successfulOutputs($batchId, $sampleCount, $this->sampleIndexes($samples));
        $missing = [];

        foreach ($samples as $index => $sample) {
            if (! array_key_exists($index, $storedOutputs)) {
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

    private function newBatchId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (RandomException $e) {
            throw new EvalRunException('Failed to generate a lazy parallel batch id.', previous: $e);
        }
    }
}
