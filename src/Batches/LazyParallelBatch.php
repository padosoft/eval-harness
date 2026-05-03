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
        $resultTtlSeconds = $this->resultTtlSecondsFor($options, $waitTimeoutSeconds, $sampleCount);
        $completed = false;

        $this->startResults($batchId, $sampleCount, $resultTtlSeconds);

        try {
            foreach (array_chunk($samples, $options->concurrency, preserve_keys: true) as $sampleWindow) {
                try {
                    $this->dispatchSampleJobs(
                        batchId: $batchId,
                        samples: $sampleWindow,
                        sampleInvocations: $sampleInvocations,
                        runnerClass: $runnerClass,
                        options: $options,
                        resultTtlSeconds: $resultTtlSeconds,
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
        $this->assertInvocationList($samples, $sampleInvocations);

        $runnerClass = $this->runnerClassFor($runner);
        $batchId = $this->newBatchId();
        $sampleCount = count($samples);
        $waitTimeoutSeconds = $options->waitTimeoutSeconds ?? $this->defaultWaitTimeoutSeconds;
        $resultTtlSeconds = $this->resultTtlSecondsFor($options, $waitTimeoutSeconds, $sampleCount);
        $this->startResults($batchId, $sampleCount, $resultTtlSeconds);
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
                    resultTtlSeconds: $resultTtlSeconds,
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
        $sampleCount = count($samples);

        try {
            $outputsByIndex = $this->collectIndexedOutputsOrNull($batchId, $samples, $sampleCount);
            if ($outputsByIndex !== null) {
                ksort($outputsByIndex);
                $this->finishResultsSafely($batchId, $sampleCount, $this->resultTtlSeconds);

                return array_values($outputsByIndex);
            }
        } catch (Throwable $e) {
            $this->abortResultsSafely($batchId, $sampleCount, $this->resultTtlSeconds);

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
            "Lazy parallel batch '%s' did not produce outputs within %d seconds for sample ids: %s. Increase the batch wait timeout, confirm queue workers are running, and confirm the batch result cache is shared with workers.",
            $batchId,
            $timeoutSeconds,
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
        int $resultTtlSeconds,
    ): void {
        foreach ($samples as $index => $sample) {
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

        $constructor = (new ReflectionClass($runnerClass))->getConstructor();
        if ($constructor !== null) {
            foreach ($constructor->getParameters() as $parameter) {
                if ($parameter->isOptional()) {
                    continue;
                }

                $type = $parameter->getType();
                if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    throw new EvalRunException(
                        'Lazy parallel batch mode requires a container-resolvable SampleRunner class; scalar constructor state from the caller instance cannot be preserved by queued workers.',
                    );
                }
            }
        }

        return $runnerClass;
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

    private function resultTtlSecondsFor(BatchOptions $options, int $waitTimeoutSeconds, int $sampleCount): int
    {
        $windowCount = max(1, intdiv($sampleCount + $options->concurrency - 1, $options->concurrency));

        return max(
            $this->resultTtlSeconds,
            $waitTimeoutSeconds,
            $waitTimeoutSeconds * $windowCount,
            $options->timeoutSeconds ?? 0,
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
}
