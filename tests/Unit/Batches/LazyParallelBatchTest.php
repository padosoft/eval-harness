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

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: new RecordingBatchResultStore,
            resultTtlSeconds: 10,
        );
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
                && $job->timeout === 45
                && $job->resultTtlSeconds === 120;
        });
    }

    public function test_dispatch_ttl_covers_expected_external_queue_drain(): void
    {
        Queue::fake();

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: new RecordingBatchResultStore,
            resultTtlSeconds: 10,
        );
        $samples = $this->samples();

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(concurrency: 1, waitTimeoutSeconds: 60),
        );

        Queue::assertPushed(EvaluateSampleJob::class, static function (EvaluateSampleJob $job): bool {
            return $job->resultTtlSeconds === 120;
        });
    }

    public function test_dispatch_ttl_accepts_explicit_batch_option_floor(): void
    {
        Queue::fake();

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: new RecordingBatchResultStore,
            resultTtlSeconds: 10,
        );
        $samples = $this->samples();

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(concurrency: 1, waitTimeoutSeconds: 60, resultTtlSeconds: 300),
        );

        Queue::assertPushed(EvaluateSampleJob::class, static function (EvaluateSampleJob $job): bool {
            return $job->resultTtlSeconds === 300;
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
        $this->assertSame(
            ['first output', 'second output'],
            $batch->collectOutputs('manual-batch', $samples),
        );
    }

    public function test_collect_outputs_rejects_truncated_sample_list_without_closing_batch(): void
    {
        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        /** @var BatchResultStore $store */
        $store = $this->app->make(BatchResultStore::class);
        $samples = $this->samples();

        $store->start('truncated-batch', 2, 60);
        $store->recordSuccess('truncated-batch', 0, 's1', 'first output', 60);

        try {
            $batch->collectOutputs('truncated-batch', [$samples[0]]);
            $this->fail('Expected truncated collect sample list to fail.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString('was initialized for 2 samples; got 1 samples', $e->getMessage());
        }

        $store->recordSuccess('truncated-batch', 1, 's2', 'second output', 60);

        $this->assertSame(
            ['first output', 'second output'],
            $batch->collectOutputs('truncated-batch', $samples),
        );
    }

    public function test_collect_outputs_rejects_results_for_the_wrong_sample_id(): void
    {
        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        /** @var BatchResultStore $store */
        $store = $this->app->make(BatchResultStore::class);
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];

        $store->start('wrong-sample-batch', 1, 60);
        $store->recordSuccess('wrong-sample-batch', 0, 'other-sample', 'output', 60);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("belongs to sample 'other-sample'; expected 's1'");

        $batch->collectOutputs('wrong-sample-batch', $samples);
    }

    public function test_collect_outputs_validation_errors_do_not_close_batch_for_retry(): void
    {
        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        /** @var BatchResultStore $store */
        $store = $this->app->make(BatchResultStore::class);
        $samples = $this->samples();

        $store->start('retryable-collect-batch', 2, 60);
        $store->recordSuccess('retryable-collect-batch', 0, 's1', 'first output', 60);
        $store->recordSuccess('retryable-collect-batch', 1, 's2', 'second output', 60);

        try {
            $batch->collectOutputs('retryable-collect-batch', [$samples[1], $samples[0]]);
            $this->fail('Expected reordered sample collection to fail.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString("belongs to sample 's1'; expected 's2'", $e->getMessage());
        }

        $this->assertSame(
            ['first output', 'second output'],
            $batch->collectOutputs('retryable-collect-batch', $samples),
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
        $this->expectExceptionMessage('did not produce outputs within 1 second');
        $this->expectExceptionMessage('Increase the batch wait timeout');

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(),
        );
    }

    public function test_timeout_path_reports_late_stored_failure_before_missing_outputs(): void
    {
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];
        $batch = new LazyParallelBatch(
            dispatcher: new MissingOutputDispatcher,
            resultStore: new LateFailureAfterSlowOutputReadStore,
            defaultWaitTimeoutSeconds: 1,
        );

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Lazy parallel batch job for sample 's1' failed: worker failed late");

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(),
        );
    }

    public function test_timeout_path_rechecks_failure_after_missing_output_scan(): void
    {
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];
        $batch = new LazyParallelBatch(
            dispatcher: new MissingOutputDispatcher,
            resultStore: new LateFailureAfterMissingOutputReadStore,
            defaultWaitTimeoutSeconds: 1,
        );

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Lazy parallel batch job for sample 's1' failed: worker failed after missing scan");

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

    public function test_rejects_non_sample_invocation_entries(): void
    {
        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];

        /** @var list<SampleInvocation> $invalidInvocations */
        $invalidInvocations = [(object) ['id' => 's1']];

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must be an instance of '.SampleInvocation::class);

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $invalidInvocations,
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(),
        );
    }

    public function test_rejects_sparse_sample_arrays_before_starting_batch(): void
    {
        $store = new RecordingBatchResultStore;
        $batch = new LazyParallelBatch(
            dispatcher: new MissingOutputDispatcher,
            resultStore: $store,
        );
        /** @var array<int, DatasetSample> $samples */
        $samples = [
            1 => new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x'),
        ];
        /** @var array<int, SampleInvocation> $sampleInvocations */
        $sampleInvocations = [
            1 => SampleInvocation::fromDatasetSample($samples[1]),
        ];

        try {
            $batch->dispatch(
                samples: $samples,
                sampleInvocations: $sampleInvocations,
                runner: new LazyParallelAnswerRunner,
                options: BatchOptions::lazyParallel(),
            );

            $this->fail('Expected sparse sample array rejection.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString('samples must be a zero-based list', $e->getMessage());
        }

        $this->assertSame([], $store->events);
    }

    public function test_rejects_sparse_sample_invocations_before_starting_batch(): void
    {
        $store = new RecordingBatchResultStore;
        $batch = new LazyParallelBatch(
            dispatcher: new MissingOutputDispatcher,
            resultStore: $store,
        );
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];
        /** @var array<int, SampleInvocation> $sampleInvocations */
        $sampleInvocations = [
            1 => SampleInvocation::fromDatasetSample($samples[0]),
        ];

        try {
            $batch->dispatch(
                samples: $samples,
                sampleInvocations: $sampleInvocations,
                runner: new LazyParallelAnswerRunner,
                options: BatchOptions::lazyParallel(),
            );

            $this->fail('Expected sparse SampleInvocation array rejection.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString('SampleInvocations must be a zero-based list', $e->getMessage());
        }

        $this->assertSame([], $store->events);
    }

    public function test_rejects_scalar_constructor_state_because_workers_resolve_fresh_runner_instances(): void
    {
        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('scalar constructor state from the caller instance cannot be preserved');

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new StatefulLazyParallelRunner('configured output'),
            options: BatchOptions::lazyParallel(),
        );
    }

    public function test_allows_container_resolvable_runner_constructor_dependencies(): void
    {
        Queue::fake();

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: new RecordingBatchResultStore,
            container: $this->app,
        );
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new DependencyInjectedLazyParallelRunner(new LazyParallelRunnerDependency),
            options: BatchOptions::lazyParallel(),
        );

        Queue::assertPushed(EvaluateSampleJob::class, static function (EvaluateSampleJob $job): bool {
            return $job->runnerClass === DependencyInjectedLazyParallelRunner::class;
        });
    }

    public function test_allows_constructor_injected_dependencies_stored_under_different_property_names(): void
    {
        Queue::fake();

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: new RecordingBatchResultStore,
            container: $this->app,
        );
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new RenamedDependencyLazyParallelRunner(new LazyParallelRunnerDependency),
            options: BatchOptions::lazyParallel(),
        );

        Queue::assertPushed(EvaluateSampleJob::class, static function (EvaluateSampleJob $job): bool {
            return $job->runnerClass === RenamedDependencyLazyParallelRunner::class;
        });
    }

    public function test_rejects_preconfigured_runner_properties_because_workers_resolve_fresh_instances(): void
    {
        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('preconfigured runner instance state remains serial-only');

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new PreconfiguredLazyParallelRunner,
            options: BatchOptions::lazyParallel(),
        );
    }

    public function test_rejects_caller_specific_object_runner_state(): void
    {
        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('caller-specific object configuration remains serial-only');

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new ObjectConfiguredLazyParallelRunner(new LazyParallelRunnerConfig),
            options: BatchOptions::lazyParallel(),
        );
    }

    public function test_result_store_failures_are_wrapped_as_eval_run_exceptions(): void
    {
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];
        $batch = new LazyParallelBatch(
            dispatcher: new MissingOutputDispatcher,
            resultStore: new ThrowingStartBatchResultStore,
        );

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Failed to initialize lazy parallel batch result store');
        $this->expectExceptionMessage('redis down');

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(),
        );
    }

    public function test_cleanup_failures_do_not_mask_dispatch_errors(): void
    {
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];
        $batch = new LazyParallelBatch(
            dispatcher: new AlwaysThrowingDispatcher,
            resultStore: new ThrowingAbortBatchResultStore,
        );

        try {
            $batch->dispatch(
                samples: $samples,
                sampleInvocations: $this->sampleInvocations($samples),
                runner: new LazyParallelAnswerRunner,
                options: BatchOptions::lazyParallel(),
            );

            $this->fail('Expected dispatch error.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString('Failed to dispatch lazy parallel batch', $e->getMessage());
            $this->assertStringContainsString('queue unavailable', $e->getMessage());
            $this->assertStringNotContainsString('cleanup down', $e->getMessage());
        }
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

final class StatefulLazyParallelRunner implements SampleRunner
{
    public function __construct(
        private readonly string $answer,
    ) {}

    public function run(SampleInvocation $sample): string
    {
        return $this->answer;
    }
}

final class LazyParallelRunnerDependency
{
    //
}

final class LazyParallelRunnerConfig
{
    public function __construct(
        public readonly string $answer = 'default output',
    ) {}
}

final class DependencyInjectedLazyParallelRunner implements SampleRunner
{
    public function __construct(
        private readonly LazyParallelRunnerDependency $dependency,
    ) {}

    public function run(SampleInvocation $sample): string
    {
        return get_debug_type($this->dependency);
    }
}

final class RenamedDependencyLazyParallelRunner implements SampleRunner
{
    private readonly LazyParallelRunnerDependency $service;

    public function __construct(LazyParallelRunnerDependency $dependency)
    {
        $this->service = $dependency;
    }

    public function run(SampleInvocation $sample): string
    {
        return get_debug_type($this->service);
    }
}

final class ObjectConfiguredLazyParallelRunner implements SampleRunner
{
    public function __construct(
        private readonly LazyParallelRunnerConfig $config,
    ) {}

    public function run(SampleInvocation $sample): string
    {
        return $this->config->answer;
    }
}

final class PreconfiguredLazyParallelRunner implements SampleRunner
{
    public string $answer = 'configured output';

    public function run(SampleInvocation $sample): string
    {
        return $this->answer;
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

final class AlwaysThrowingDispatcher implements Dispatcher
{
    public function dispatch($command): mixed
    {
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

final class RecordingBatchResultStore implements BatchResultStore
{
    /** @var list<string> */
    public array $events = [];

    private ?int $sampleCount = null;

    private ?int $ttlSeconds = null;

    /** @var array<int, array{sample_id: string, actual_output: string}> */
    private array $outputs = [];

    /** @var array<int, array{sample_id: string, error: string}> */
    private array $failures = [];

    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        $this->events[] = 'start:'.$sampleCount;
        $this->sampleCount = $sampleCount;
        $this->ttlSeconds = $ttlSeconds;
    }

    public function sampleCount(string $batchId): ?int
    {
        $this->events[] = 'sample-count';

        return $this->sampleCount;
    }

    public function ttlSeconds(string $batchId): ?int
    {
        $this->events[] = 'ttl';

        return $this->ttlSeconds;
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
        $this->outputs[$index] = ['sample_id' => $sampleId, 'actual_output' => $actualOutput];
    }

    public function recordFailure(string $batchId, int $index, string $sampleId, string $error, int $ttlSeconds): void
    {
        $this->events[] = 'failure:'.$sampleId;
        $this->failures[$index] = ['sample_id' => $sampleId, 'error' => $error];
    }

    public function successfulResults(string $batchId, int $sampleCount, ?array $indexes = null): array
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

final class LateFailureAfterSlowOutputReadStore implements BatchResultStore
{
    private bool $failureAvailable = false;

    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        //
    }

    public function sampleCount(string $batchId): ?int
    {
        return 1;
    }

    public function ttlSeconds(string $batchId): ?int
    {
        return 60;
    }

    public function finish(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        //
    }

    public function abort(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        //
    }

    public function recordSuccess(string $batchId, int $index, string $sampleId, string $actualOutput, int $ttlSeconds): void
    {
        //
    }

    public function recordFailure(string $batchId, int $index, string $sampleId, string $error, int $ttlSeconds): void
    {
        //
    }

    public function successfulResults(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        usleep(1_100_000);
        $this->failureAvailable = true;

        return [];
    }

    public function failures(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        if (! $this->failureAvailable) {
            return [];
        }

        return [
            0 => ['sample_id' => 's1', 'error' => 'worker failed late'],
        ];
    }
}

final class LateFailureAfterMissingOutputReadStore implements BatchResultStore
{
    private bool $failureAvailable = false;

    private int $successfulReadCount = 0;

    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        //
    }

    public function sampleCount(string $batchId): ?int
    {
        return 1;
    }

    public function ttlSeconds(string $batchId): ?int
    {
        return 60;
    }

    public function finish(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        //
    }

    public function abort(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        //
    }

    public function recordSuccess(string $batchId, int $index, string $sampleId, string $actualOutput, int $ttlSeconds): void
    {
        //
    }

    public function recordFailure(string $batchId, int $index, string $sampleId, string $error, int $ttlSeconds): void
    {
        //
    }

    public function successfulResults(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        $this->successfulReadCount++;

        if ($this->successfulReadCount === 1) {
            usleep(1_100_000);
        }

        if ($this->successfulReadCount > 1) {
            $this->failureAvailable = true;
        }

        return [];
    }

    public function failures(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        if (! $this->failureAvailable) {
            return [];
        }

        return [
            0 => ['sample_id' => 's1', 'error' => 'worker failed after missing scan'],
        ];
    }
}

final class ThrowingStartBatchResultStore implements BatchResultStore
{
    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        throw new \RuntimeException('redis down');
    }

    public function finish(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        //
    }

    public function sampleCount(string $batchId): ?int
    {
        return null;
    }

    public function ttlSeconds(string $batchId): ?int
    {
        return null;
    }

    public function abort(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        //
    }

    public function recordSuccess(string $batchId, int $index, string $sampleId, string $actualOutput, int $ttlSeconds): void
    {
        //
    }

    public function recordFailure(string $batchId, int $index, string $sampleId, string $error, int $ttlSeconds): void
    {
        //
    }

    public function successfulResults(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        return [];
    }

    public function failures(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        return [];
    }
}

final class ThrowingAbortBatchResultStore implements BatchResultStore
{
    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        //
    }

    public function finish(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        //
    }

    public function sampleCount(string $batchId): ?int
    {
        return null;
    }

    public function ttlSeconds(string $batchId): ?int
    {
        return null;
    }

    public function abort(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        throw new \RuntimeException('cleanup down');
    }

    public function recordSuccess(string $batchId, int $index, string $sampleId, string $actualOutput, int $ttlSeconds): void
    {
        //
    }

    public function recordFailure(string $batchId, int $index, string $sampleId, string $error, int $ttlSeconds): void
    {
        //
    }

    public function successfulResults(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        return [];
    }

    public function failures(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        return [];
    }
}
