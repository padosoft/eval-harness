<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Batches;

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
            options: BatchOptions::lazyParallel(concurrency: 3, queue: 'evals', timeoutSeconds: 45),
        );

        Queue::assertPushed(EvaluateSampleJob::class, 2);
        Queue::assertPushed(EvaluateSampleJob::class, static function (EvaluateSampleJob $job) use ($batchId): bool {
            return $job->batchId === $batchId
                && $job->sampleId === 's1'
                && $job->queue === 'evals'
                && $job->timeout === 45;
        });
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
