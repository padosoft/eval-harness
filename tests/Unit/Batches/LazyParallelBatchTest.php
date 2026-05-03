<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Batches;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Batches\BatchResultStore;
use Padosoft\EvalHarness\Batches\LazyParallelBatch;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Jobs\EvaluateSampleJob;
use Padosoft\EvalHarness\Tests\TestCase;

final class LazyParallelBatchTest extends TestCase
{
    public function test_runs_jobs_through_sync_queue_and_preserves_dataset_order(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        $samples = $this->samples();

        $outputs = $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(concurrency: 2, queue: 'evals', timeoutSeconds: 5),
        );

        $this->assertSame(['first output', 'second output'], $outputs);
    }

    public function test_dispatch_pushes_jobs_to_configured_queue_without_running_queue_fake(): void
    {
        Queue::fake();

        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        $samples = $this->samples();

        $batchId = $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(concurrency: 3, queue: 'evals', timeoutSeconds: 45, waitTimeoutSeconds: 120),
        );

        Queue::assertPushed(EvaluateSampleJob::class, 2);
        Queue::assertPushed(EvaluateSampleJob::class, static function (EvaluateSampleJob $job) use ($batchId): bool {
            return $job->batchId === $batchId
                && $job->sampleId === 's1'
                && $job->queue === 'evals'
                && $job->timeout === 45;
        });
    }

    public function test_dispatch_cleans_result_store_when_dispatcher_fails(): void
    {
        $samples = $this->samples();
        $store = new RecordingBatchResultStore;
        $batch = new LazyParallelBatch(
            dispatcher: new ThrowingDispatcher($store),
            resultStore: $store,
        );

        try {
            $batch->dispatch(
                samples: $samples,
                sampleInvocations: $this->sampleInvocations($samples),
                runner: new LazyParallelAnswerRunner,
                options: BatchOptions::lazyParallel(),
            );

            $this->fail('Expected dispatch failure.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString('Failed to dispatch lazy parallel batch', $e->getMessage());
            $this->assertStringContainsString('queue unavailable', $e->getMessage());
        }

        $this->assertSame([
            'start:2',
            'dispatch:s1',
            'failures:2',
            'abort:2',
        ], $store->events);
    }

    public function test_collect_outputs_preserves_order_when_jobs_finish_out_of_order(): void
    {
        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        /** @var BatchResultStore $store */
        $store = $this->app->make(BatchResultStore::class);
        $samples = $this->samples();

        $store->start('manual-batch', 2, 60);
        $store->recordSuccess('manual-batch', 1, 's2', 'second output', 60);
        $store->recordSuccess('manual-batch', 0, 's1', 'first output', 60);

        $this->assertSame(
            ['first output', 'second output'],
            $batch->collectOutputs('manual-batch', $samples),
        );
    }

    public function test_run_honors_concurrency_windows_before_dispatching_more_jobs(): void
    {
        $samples = [
            new DatasetSample(id: 's1', input: ['answer' => 'first output'], expectedOutput: 'first output'),
            new DatasetSample(id: 's2', input: ['answer' => 'second output'], expectedOutput: 'second output'),
            new DatasetSample(id: 's3', input: ['answer' => 'third output'], expectedOutput: 'third output'),
        ];
        $store = new RecordingBatchResultStore;
        $batch = new LazyParallelBatch(
            dispatcher: new RecordingDispatcher($store),
            resultStore: $store,
            defaultWaitTimeoutSeconds: 1,
        );

        $outputs = $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(concurrency: 2),
        );

        $this->assertSame(['first output', 'second output', 'third output'], $outputs);
        $this->assertSame([
            'start:3',
            'dispatch:s1',
            'success:s1',
            'dispatch:s2',
            'success:s2',
            'failures:3',
            'outputs:3',
            'dispatch:s3',
            'success:s3',
            'failures:3',
            'outputs:3',
            'finish:3',
        ], $store->events);
    }

    public function test_runner_failures_are_reported_by_sample_id(): void
    {
        $this->app['config']->set('queue.default', 'sync');

        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Lazy parallel batch job for sample 's1' failed: runner exploded");

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelFailingRunner,
            options: BatchOptions::lazyParallel(timeoutSeconds: 5),
        );
    }

    public function test_timeout_message_points_to_batch_wait_timeout(): void
    {
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];
        $batch = new LazyParallelBatch(
            dispatcher: new MissingOutputDispatcher,
            resultStore: new RecordingBatchResultStore,
            defaultWaitTimeoutSeconds: 1,
        );

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('did not produce outputs within 1 seconds');
        $this->expectExceptionMessage('Increase the batch wait timeout');

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(),
        );
    }

    public function test_rejects_anonymous_runners_because_workers_cannot_autoload_them(): void
    {
        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];
        $runner = new class implements SampleRunner
        {
            public function run(SampleInvocation $sample): string
            {
                return (string) $sample->input['answer'];
            }
        };

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('requires a concrete, autoloadable SampleRunner class');

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: $runner,
            options: BatchOptions::lazyParallel(),
        );
    }

    /**
     * @return list<DatasetSample>
     */
    private function samples(): array
    {
        return [
            new DatasetSample(id: 's1', input: ['answer' => 'first output'], expectedOutput: 'first output'),
            new DatasetSample(id: 's2', input: ['answer' => 'second output'], expectedOutput: 'second output'),
        ];
    }

    /**
     * @param  list<DatasetSample>  $samples
     * @return list<SampleInvocation>
     */
    private function sampleInvocations(array $samples): array
    {
        return array_map(
            static fn (DatasetSample $sample): SampleInvocation => SampleInvocation::fromDatasetSample($sample),
            $samples,
        );
    }
}

final class LazyParallelAnswerRunner implements SampleRunner
{
    public function run(SampleInvocation $sample): string
    {
        return (string) $sample->input['answer'];
    }
}

final class LazyParallelFailingRunner implements SampleRunner
{
    public function run(SampleInvocation $sample): string
    {
        throw new \RuntimeException('runner exploded');
    }
}

final class RecordingDispatcher implements Dispatcher
{
    public function __construct(
        private readonly RecordingBatchResultStore $store,
    ) {}

    public function dispatch($command): mixed
    {
        if (! $command instanceof EvaluateSampleJob) {
            return null;
        }

        $this->store->events[] = 'dispatch:'.$command->sampleId;
        $this->store->recordSuccess(
            batchId: $command->batchId,
            index: $command->index,
            sampleId: $command->sampleId,
            actualOutput: (string) $command->sample->input['answer'],
            ttlSeconds: $command->resultTtlSeconds,
        );

        return null;
    }

    public function dispatchSync($command, $handler = null): mixed
    {
        return $this->dispatch($command);
    }

    public function dispatchNow($command, $handler = null): mixed
    {
        return $this->dispatch($command);
    }

    public function dispatchAfterResponse($command, $handler = null): void
    {
        $this->dispatch($command);
    }

    public function chain($jobs = null): mixed
    {
        return null;
    }

    public function hasCommandHandler($command): bool
    {
        return false;
    }

    public function getCommandHandler($command): mixed
    {
        return null;
    }

    public function pipeThrough(array $pipes): self
    {
        return $this;
    }

    public function map(array $map): self
    {
        return $this;
    }
}

final class ThrowingDispatcher implements Dispatcher
{
    public function __construct(
        private readonly RecordingBatchResultStore $store,
    ) {}

    public function dispatch($command): mixed
    {
        if ($command instanceof EvaluateSampleJob) {
            $this->store->events[] = 'dispatch:'.$command->sampleId;
        }

        throw new \RuntimeException('queue unavailable');
    }

    public function dispatchSync($command, $handler = null): mixed
    {
        return $this->dispatch($command);
    }

    public function dispatchNow($command, $handler = null): mixed
    {
        return $this->dispatch($command);
    }

    public function dispatchAfterResponse($command, $handler = null): void
    {
        $this->dispatch($command);
    }

    public function chain($jobs = null): mixed
    {
        return null;
    }

    public function hasCommandHandler($command): bool
    {
        return false;
    }

    public function getCommandHandler($command): mixed
    {
        return null;
    }

    public function pipeThrough(array $pipes): self
    {
        return $this;
    }

    public function map(array $map): self
    {
        return $this;
    }
}

final class MissingOutputDispatcher implements Dispatcher
{
    public function dispatch($command): mixed
    {
        return null;
    }

    public function dispatchSync($command, $handler = null): mixed
    {
        return $this->dispatch($command);
    }

    public function dispatchNow($command, $handler = null): mixed
    {
        return $this->dispatch($command);
    }

    public function dispatchAfterResponse($command, $handler = null): void
    {
        $this->dispatch($command);
    }

    public function chain($jobs = null): mixed
    {
        return null;
    }

    public function hasCommandHandler($command): bool
    {
        return false;
    }

    public function getCommandHandler($command): mixed
    {
        return null;
    }

    public function pipeThrough(array $pipes): self
    {
        return $this;
    }

    public function map(array $map): self
    {
        return $this;
    }
}

final class RecordingBatchResultStore implements BatchResultStore
{
    /** @var list<string> */
    public array $events = [];

    /** @var array<int, string> */
    private array $outputs = [];

    /** @var array<int, array{sample_id: string, error: string}> */
    private array $failures = [];

    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        $this->events[] = 'start:'.$sampleCount;
    }

    public function finish(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        $this->events[] = 'finish:'.$sampleCount;
        $this->outputs = [];
        $this->failures = [];
    }

    public function abort(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        $this->events[] = 'abort:'.$sampleCount;
        $this->outputs = [];
        $this->failures = [];
    }

    public function recordSuccess(string $batchId, int $index, string $sampleId, string $actualOutput, int $ttlSeconds): void
    {
        $this->events[] = 'success:'.$sampleId;
        $this->outputs[$index] = $actualOutput;
    }

    public function recordFailure(string $batchId, int $index, string $sampleId, string $error, int $ttlSeconds): void
    {
        $this->events[] = 'failure:'.$sampleId;
        $this->failures[$index] = ['sample_id' => $sampleId, 'error' => $error];
    }

    public function successfulOutputs(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        $this->events[] = 'outputs:'.$sampleCount;

        if ($indexes === null) {
            return $this->outputs;
        }

        return array_intersect_key($this->outputs, array_flip($indexes));
    }

    public function failures(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        $this->events[] = 'failures:'.$sampleCount;

        if ($indexes === null) {
            return $this->failures;
        }

        return array_intersect_key($this->failures, array_flip($indexes));
    }
}
