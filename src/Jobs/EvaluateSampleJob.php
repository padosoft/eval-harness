<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Container\Container as LaravelContainer;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Padosoft\EvalHarness\Batches\BatchResultStore;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use ReflectionClass;
use Throwable;

/**
 * Queue job that evaluates one dataset sample through a SampleRunner.
 */
final class EvaluateSampleJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public bool $failOnTimeout = true;

    public ?int $timeout = null;

    /**
     * @param  class-string<SampleRunner>  $runnerClass
     */
    public function __construct(
        public readonly string $batchId,
        public readonly int $index,
        public readonly string $sampleId,
        public readonly SampleInvocation $sample,
        public readonly string $runnerClass,
        public readonly int $resultTtlSeconds,
        ?int $timeoutSeconds = null,
    ) {
        if ($index < 0) {
            throw new EvalRunException('Queued sample index must be greater than or equal to 0.');
        }

        if ($sampleId !== $sample->id) {
            throw new EvalRunException(sprintf(
                "Queued sample id '%s' must match SampleInvocation id '%s'.",
                $sampleId,
                $sample->id,
            ));
        }

        if ($resultTtlSeconds < 1) {
            throw new EvalRunException('Batch result TTL must be greater than or equal to 1 second.');
        }

        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new EvalRunException('Queued sample timeout must be null or greater than or equal to 1 second.');
        }

        if (str_contains($runnerClass, "\0") || str_contains($runnerClass, '@anonymous')) {
            throw new EvalRunException(
                'Queued sample runner must be a concrete, autoloadable SampleRunner class.',
            );
        }

        if (! is_a($runnerClass, SampleRunner::class, true)) {
            throw new EvalRunException(sprintf(
                "Queued sample runner '%s' must implement %s.",
                $runnerClass,
                SampleRunner::class,
            ));
        }

        if (! (new ReflectionClass($runnerClass))->isInstantiable()) {
            throw new EvalRunException(sprintf(
                "Queued sample runner '%s' must be a concrete, instantiable SampleRunner class.",
                $runnerClass,
            ));
        }

        if ($timeoutSeconds !== null) {
            $this->timeout = $timeoutSeconds;
        }
    }

    public function handle(Container $container, BatchResultStore $resultStore): void
    {
        $runner = $container->make($this->runnerClass);
        if (! $runner instanceof SampleRunner) {
            throw new EvalRunException(sprintf(
                "Queued sample runner '%s' must resolve to %s; got %s.",
                $this->runnerClass,
                SampleRunner::class,
                get_debug_type($runner),
            ));
        }

        $actualOutput = $runner->run($this->sample);

        $resultStore->recordSuccess(
            batchId: $this->batchId,
            index: $this->index,
            sampleId: $this->sampleId,
            actualOutput: $actualOutput,
            ttlSeconds: $this->resultTtlSeconds,
        );
    }

    public function failed(?Throwable $e): void
    {
        $failureMessage = $this->failureMessage($e);

        try {
            /** @var BatchResultStore $resultStore */
            $resultStore = LaravelContainer::getInstance()->make(BatchResultStore::class);

            $resultStore->recordFailure(
                batchId: $this->batchId,
                index: $this->index,
                sampleId: $this->sampleId,
                error: $failureMessage,
                ttlSeconds: $this->resultTtlSeconds,
            );
        } catch (Throwable $storeError) {
            throw new EvalRunException(sprintf(
                "Failed to record lazy parallel batch failure for sample '%s' after queue job failed with: %s. Result store error: %s.",
                $this->sampleId,
                $failureMessage,
                $storeError->getMessage() !== '' ? $storeError->getMessage() : $storeError::class,
            ), previous: $storeError);
        }
    }

    private function failureMessage(?Throwable $e): string
    {
        if ($e === null) {
            return 'Queue job failed without an exception.';
        }

        return $e->getMessage() !== '' ? $e->getMessage() : $e::class;
    }
}
