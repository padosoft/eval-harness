<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Batches;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Batches\BatchProgressReporter;
use Padosoft\EvalHarness\Batches\BatchResultStore;
use Padosoft\EvalHarness\Batches\BatchTerminalProgressReporter;
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

    public function test_dispatch_ttl_scales_with_concurrency_keyed_drain_time(): void
    {
        // dispatch() is fire-and-return on the producer side, but the
        // result store still has to live long enough for workers to
        // drain the queue. Worker pool capacity is unknown to the
        // harness; concurrency is the closest proxy. With 2 samples
        // and concurrency=1, drainBatches=2 and the TTL floor is
        // 2 * max(waitTimeout=60, timeout=0) = 120 seconds. Operators
        // with larger pools should override via
        // BatchOptions::lazyParallel(resultTtlSeconds: ...).
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

    public function test_dispatch_ttl_uses_per_job_timeout_for_queue_drain(): void
    {
        Queue::fake();

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: new RecordingBatchResultStore,
            resultTtlSeconds: 10,
        );
        $samples = [
            new DatasetSample(id: 's1', input: ['answer' => 'first output'], expectedOutput: 'first output'),
            new DatasetSample(id: 's2', input: ['answer' => 'second output'], expectedOutput: 'second output'),
            new DatasetSample(id: 's3', input: ['answer' => 'third output'], expectedOutput: 'third output'),
        ];

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(concurrency: 1, timeoutSeconds: 300, waitTimeoutSeconds: 60),
        );

        // 3 samples / concurrency=1 = 3 drain batches. drainSeconds =
        // 3 * max(60, 300) = 900 seconds. The per-job timeout
        // dominates the per-window wait timeout here.
        Queue::assertPushed(EvaluateSampleJob::class, static function (EvaluateSampleJob $job): bool {
            return $job->resultTtlSeconds === 900;
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

    public function test_dispatch_reports_earlier_window_failure_when_later_window_dispatch_fails(): void
    {
        $samples = $this->samples();
        $store = new RecordingBatchResultStore;
        $batch = new LazyParallelBatch(
            dispatcher: new FailureBeforeLaterDispatchThrowsDispatcher($store),
            resultStore: $store,
        );

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Lazy parallel batch job for sample 's1' failed: first failed");

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(concurrency: 1),
        );
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

    public function test_collect_outputs_rechecks_completion_after_missing_output_scan(): void
    {
        $batch = new LazyParallelBatch(
            dispatcher: new MissingOutputDispatcher,
            resultStore: new CompleteAfterMissingOutputReadStore,
        );
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];

        $this->assertSame(['first output'], $batch->collectOutputs('completing-batch', $samples));
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

    public function test_timeout_path_rechecks_completed_outputs_before_throwing(): void
    {
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];
        $batch = new LazyParallelBatch(
            dispatcher: new MissingOutputDispatcher,
            resultStore: new CompleteAfterTimeoutMissingOutputReadStore,
            defaultWaitTimeoutSeconds: 1,
        );

        $this->assertSame(['first output'], $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(),
        ));
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

    public function test_rejects_non_dataset_sample_entries_before_starting_batch(): void
    {
        $store = new RecordingBatchResultStore;
        $batch = new LazyParallelBatch(
            dispatcher: new MissingOutputDispatcher,
            resultStore: $store,
        );

        try {
            $batch->dispatch(
                samples: [(object) ['id' => 's1']],
                sampleInvocations: [new SampleInvocation(id: 's1', input: ['answer' => 'x'])],
                runner: new LazyParallelAnswerRunner,
                options: BatchOptions::lazyParallel(),
            );

            $this->fail('Expected malformed sample rejection.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString('sample at index 0 must be an instance of '.DatasetSample::class, $e->getMessage());
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

    public function test_collect_outputs_rejects_sparse_sample_arrays(): void
    {
        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        /** @var array<int, DatasetSample> $samples */
        $samples = [
            1 => new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x'),
        ];

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('samples must be a zero-based list');

        $batch->collectOutputs('manual-batch', $samples);
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
        $this->expectExceptionMessage('initialized runner object state to match a fresh container-resolved runner');

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new ObjectConfiguredLazyParallelRunner(new LazyParallelRunnerConfig('caller output')),
            options: BatchOptions::lazyParallel(),
        );
    }

    public function test_allows_container_resolvable_dependency_objects_with_scalar_internal_config(): void
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
            runner: new ObjectConfiguredLazyParallelRunner(new LazyParallelRunnerConfig),
            options: BatchOptions::lazyParallel(),
        );

        Queue::assertPushed(EvaluateSampleJob::class, static function (EvaluateSampleJob $job): bool {
            return $job->runnerClass === ObjectConfiguredLazyParallelRunner::class;
        });
    }

    public function test_rejects_singleton_runner_instances_because_workers_resolve_fresh_processes(): void
    {
        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);
        $samples = [new DatasetSample(id: 's1', input: ['answer' => 'x'], expectedOutput: 'x')];
        $runner = new DependencyInjectedLazyParallelRunner(new LazyParallelRunnerDependency);
        $this->app->instance(DependencyInjectedLazyParallelRunner::class, $runner);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('requires the container to resolve a fresh SampleRunner instance');

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: $runner,
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

    public function test_dispatch_ttl_is_invariant_to_chunk_size(): void
    {
        // dispatch() is fire-and-return; chunkSize controls only
        // producer-side enqueue batching, which dispatch() does not
        // wait between. Older versions multiplied TTL by sampleCount /
        // chunkSize, which over-retained externally dispatched
        // batches by hours when chunkSize was small. The invariant
        // now: chunkSize MUST NOT change the dispatch() TTL — the
        // floor stays max(default, waitTimeout, timeout, configuredTTL).
        Queue::fake();

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: new RecordingBatchResultStore,
            resultTtlSeconds: 10,
        );

        $samples = [];
        for ($i = 0; $i < 10; $i++) {
            $samples[] = new DatasetSample(
                id: 's'.($i + 1),
                input: ['answer' => 'a'.($i + 1)],
                expectedOutput: 'a'.($i + 1),
            );
        }

        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 10,
                queue: 'evals',
                waitTimeoutSeconds: 60,
                chunkSize: 1,
            ),
        );

        Queue::assertPushed(EvaluateSampleJob::class, static function (EvaluateSampleJob $job): bool {
            // max(default 10, waitTimeout 60, timeout 0, configured 0) = 60.
            return $job->resultTtlSeconds === 60;
        });
    }

    public function test_dispatch_does_not_throttle_on_rate_limit(): void
    {
        // Regression: dispatch() must remain the documented
        // fire-and-return flow. If rate-limit throttling leaked back
        // into dispatch() (as happened on a prior commit), even small
        // batches with a low rateLimit would block the producer for
        // wall-clock seconds before returning a batch id. Pin the
        // wall-clock budget: 12 dispatches at rateLimit=2 /
        // rateWindowSeconds=60 must not sleep across multiple rate
        // windows. We assert finishing well under one rate window
        // (allowing generous CI overhead).
        Queue::fake();

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: new RecordingBatchResultStore,
            resultTtlSeconds: 10,
        );

        $samples = [];
        for ($i = 0; $i < 12; $i++) {
            $samples[] = new DatasetSample(
                id: 's'.($i + 1),
                input: ['answer' => 'a'.($i + 1)],
                expectedOutput: 'a'.($i + 1),
            );
        }

        $start = microtime(true);
        $batch->dispatch(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 12,
                queue: 'evals',
                rateLimit: 2,
                rateWindowSeconds: 60,
            ),
        );
        $elapsedSeconds = microtime(true) - $start;

        Queue::assertPushed(EvaluateSampleJob::class, 12);
        $this->assertLessThan(
            10.0,
            $elapsedSeconds,
            'dispatch() must remain fire-and-return: throttling on the rate limiter would consume multiple 60s rate windows for 12 samples at rateLimit=2.',
        );
    }

    public function test_run_uses_effective_chunk_size_for_producer_window(): void
    {
        // RecordingBatchResultStore captures one `outputs:` event per
        // `collectIndexedOutputsOrNull()` call, and that helper is invoked
        // exactly once per producer window under the sync queue (because
        // sync workers write the result before the producer polls). So the
        // count of `outputs:` events equals the number of producer windows
        // and lets us prove the chunk size actually drives windowing,
        // independent of the total dispatched job count.
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        $store = new RecordingBatchResultStore;
        $this->app->instance(BatchResultStore::class, $store);

        /** @var LazyParallelBatch $batch */
        $batch = $this->app->make(LazyParallelBatch::class);

        $samples = [
            new DatasetSample(id: 's1', input: ['answer' => 'a'], expectedOutput: 'a'),
            new DatasetSample(id: 's2', input: ['answer' => 'b'], expectedOutput: 'b'),
            new DatasetSample(id: 's3', input: ['answer' => 'c'], expectedOutput: 'c'),
            new DatasetSample(id: 's4', input: ['answer' => 'd'], expectedOutput: 'd'),
        ];

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 4,
                queue: 'evals',
                timeoutSeconds: 5,
                chunkSize: 2,
            ),
        );

        $outputsReads = count(array_filter(
            $store->events,
            static fn (string $event): bool => str_starts_with($event, 'outputs:'),
        ));

        $this->assertSame(
            2,
            $outputsReads,
            'chunkSize=2 with 4 samples must iterate two producer windows, not one.',
        );
    }

    public function test_run_invokes_progress_reporter_at_checkpoint_intervals(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        $reporter = new RecordingBatchProgressReporter;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: $reporter,
        );

        $samples = [
            new DatasetSample(id: 's1', input: ['answer' => 'a'], expectedOutput: 'a'),
            new DatasetSample(id: 's2', input: ['answer' => 'b'], expectedOutput: 'b'),
            new DatasetSample(id: 's3', input: ['answer' => 'c'], expectedOutput: 'c'),
            new DatasetSample(id: 's4', input: ['answer' => 'd'], expectedOutput: 'd'),
        ];

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 2,
                queue: 'evals',
                timeoutSeconds: 5,
                chunkSize: 2,
                checkpointEvery: 2,
            ),
        );

        $this->assertSame(
            [
                ['samples_completed' => 2, 'total' => 4],
                ['samples_completed' => 4, 'total' => 4],
            ],
            $reporter->checkpoints,
        );
    }

    public function test_run_emits_checkpoints_when_chunk_size_does_not_divide_interval(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        $reporter = new RecordingBatchProgressReporter;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: $reporter,
        );

        // Producer windows land on 4, 8, 12, 16, 20, 24 with chunk_size=4
        // and checkpoint_every=10, so the cumulative count never lands
        // exactly on a multiple of 10. The reporter must still fire when
        // the cumulative count crosses each multiple-of-10 threshold
        // instead of waiting only for end-of-batch.
        $samples = [];
        for ($i = 0; $i < 24; $i++) {
            $samples[] = new DatasetSample(
                id: 's'.($i + 1),
                input: ['answer' => 'a'.($i + 1)],
                expectedOutput: 'a'.($i + 1),
            );
        }

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 4,
                queue: 'evals',
                timeoutSeconds: 5,
                chunkSize: 4,
                checkpointEvery: 10,
            ),
        );

        $this->assertSame(
            [
                ['samples_completed' => 10, 'total' => 24],
                ['samples_completed' => 20, 'total' => 24],
                ['samples_completed' => 24, 'total' => 24],
            ],
            $reporter->checkpoints,
            'Reporter must emit a checkpoint exactly at each multiple-of-N threshold crossed by the cumulative count, plus a final checkpoint at end-of-batch when the total is not itself a multiple of N.',
        );
    }

    public function test_run_emits_one_checkpoint_per_threshold_when_chunk_size_exceeds_interval(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        $reporter = new RecordingBatchProgressReporter;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: $reporter,
        );

        // chunkSize=10 with checkpoint_every=3 means a single producer
        // window crosses thresholds 3, 6, and 9 at once. Every threshold
        // must still emit, and a final checkpoint must fire at end-of-batch.
        $samples = [];
        for ($i = 0; $i < 10; $i++) {
            $samples[] = new DatasetSample(
                id: 's'.($i + 1),
                input: ['answer' => 'a'.($i + 1)],
                expectedOutput: 'a'.($i + 1),
            );
        }

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 10,
                queue: 'evals',
                timeoutSeconds: 5,
                chunkSize: 10,
                checkpointEvery: 3,
            ),
        );

        $this->assertSame(
            [
                ['samples_completed' => 3, 'total' => 10],
                ['samples_completed' => 6, 'total' => 10],
                ['samples_completed' => 9, 'total' => 10],
                ['samples_completed' => 10, 'total' => 10],
            ],
            $reporter->checkpoints,
            'Reporter must fire one event per crossed threshold even when a single window spans multiple intervals.',
        );
    }

    public function test_rate_limit_window_defaults_to_sixty_seconds_when_unset(): void
    {
        // Documented contract: when rateLimit is set without
        // rateWindowSeconds, the producer throttles using a 60-second
        // rolling window. Reflection avoids dragging real wall-clock
        // timing into the assertion.
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: new RecordingBatchResultStore,
            resultTtlSeconds: 10,
        );

        $reflection = new \ReflectionMethod(LazyParallelBatch::class, 'rateLimitWindow');
        $reflection->setAccessible(true);

        $explicitWindow = $reflection->invoke(
            $batch,
            BatchOptions::lazyParallel(rateLimit: 5, rateWindowSeconds: 30),
        );
        $this->assertNotNull($explicitWindow);
        $this->assertSame(5, $explicitWindow->rateLimit);
        $this->assertSame(30, $explicitWindow->rateWindowSeconds);

        $defaultWindow = $reflection->invoke(
            $batch,
            BatchOptions::lazyParallel(rateLimit: 7),
        );
        $this->assertNotNull($defaultWindow);
        $this->assertSame(7, $defaultWindow->rateLimit);
        $this->assertSame(60, $defaultWindow->rateWindowSeconds);

        $unsetWindow = $reflection->invoke($batch, BatchOptions::lazyParallel());
        $this->assertNull($unsetWindow);
    }

    public function test_terminal_reporter_receives_success_status_on_happy_path(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        $reporter = new RecordingTerminalProgressReporter;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: $reporter,
        );

        $samples = $this->samples();

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 2,
                queue: 'evals',
                timeoutSeconds: 5,
                checkpointEvery: 1,
            ),
        );

        $this->assertSame(
            [['status' => 'success', 'samples_completed' => 2, 'total' => 2]],
            $reporter->terminalEvents,
        );
    }

    public function test_terminal_reporter_receives_failure_status_on_catch_path(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        $reporter = new RecordingTerminalProgressReporter;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: $reporter,
        );

        $samples = $this->samples();

        try {
            $batch->run(
                samples: $samples,
                sampleInvocations: $this->sampleInvocations($samples),
                runner: new LazyParallelFailingRunner,
                options: BatchOptions::lazyParallel(
                    concurrency: 2,
                    queue: 'evals',
                    timeoutSeconds: 5,
                    checkpointEvery: 1,
                ),
            );
            $this->fail('Expected the failing runner to surface as EvalRunException.');
        } catch (EvalRunException) {
            // Expected.
        }

        $this->assertCount(1, $reporter->terminalEvents);
        $this->assertSame('failure', $reporter->terminalEvents[0]['status']);
        $this->assertSame(2, $reporter->terminalEvents[0]['total']);
    }

    public function test_terminal_reporter_receives_empty_status_for_empty_batch(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        $reporter = new RecordingTerminalProgressReporter;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: $reporter,
        );

        $batch->run(
            samples: [],
            sampleInvocations: [],
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 2,
                queue: 'evals',
                timeoutSeconds: 5,
                checkpointEvery: 25,
            ),
        );

        $this->assertSame(
            [['status' => 'empty', 'samples_completed' => 0, 'total' => 0]],
            $reporter->terminalEvents,
        );
    }

    public function test_run_emits_final_checkpoint_when_batch_fails_at_aligned_total(): void
    {
        // Regression for the failure-path terminal checkpoint:
        // when totalSamples is an exact multiple of checkpointEvery
        // (e.g. 4 samples + checkpoint_every=4), the success-path
        // alignment guard `totalSamples % checkpointEvery === 0` would
        // suppress the final emit. On the failure path the forced
        // helper must still fire so dashboards can distinguish a
        // failed batch from a stalled one.
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        $reporter = new RecordingBatchProgressReporter;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: $reporter,
        );

        $samples = [
            new DatasetSample(id: 's1', input: ['answer' => 'a'], expectedOutput: 'a'),
            new DatasetSample(id: 's2', input: ['answer' => 'b'], expectedOutput: 'b'),
            new DatasetSample(id: 's3', input: ['answer' => 'c'], expectedOutput: 'c'),
            new DatasetSample(id: 's4', input: ['answer' => 'd'], expectedOutput: 'd'),
        ];

        try {
            $batch->run(
                samples: $samples,
                sampleInvocations: $this->sampleInvocations($samples),
                runner: new LazyParallelFailingRunner,
                options: BatchOptions::lazyParallel(
                    concurrency: 4,
                    queue: 'evals',
                    timeoutSeconds: 5,
                    checkpointEvery: 4, // 4 % 4 = 0 (aligned)
                ),
            );
            $this->fail('Expected the failing runner to surface as EvalRunException.');
        } catch (EvalRunException) {
            // Expected.
        }

        $this->assertNotEmpty(
            $reporter->checkpoints,
            'Failed runs at aligned totals must still emit a terminal checkpoint event so dashboards can distinguish failed from stalled.',
        );
        $finalCheckpoint = $reporter->checkpoints[count($reporter->checkpoints) - 1];
        $this->assertSame(4, $finalCheckpoint['total']);
        // Failing runner produces no successes; samplesCompleted from
        // the result store is therefore 0.
        $this->assertSame(0, $finalCheckpoint['samples_completed']);
    }

    public function test_run_emits_final_checkpoint_when_batch_fails(): void
    {
        // Regression: BatchProgressReporter consumers (dashboards,
        // log forwarders) need a terminal event even when the batch
        // exits through failure. Without it, a failed run looks
        // identical to a stalled run.
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        $reporter = new RecordingBatchProgressReporter;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: $reporter,
        );

        $samples = [
            new DatasetSample(id: 's1', input: ['answer' => 'first'], expectedOutput: 'first'),
            new DatasetSample(id: 's2', input: ['answer' => 'second'], expectedOutput: 'second'),
        ];

        try {
            $batch->run(
                samples: $samples,
                sampleInvocations: $this->sampleInvocations($samples),
                runner: new LazyParallelFailingRunner,
                options: BatchOptions::lazyParallel(
                    concurrency: 2,
                    queue: 'evals',
                    timeoutSeconds: 5,
                    checkpointEvery: 25,
                ),
            );
            $this->fail('Expected the failing runner to surface as EvalRunException.');
        } catch (EvalRunException) {
            // Expected.
        }

        $this->assertNotEmpty(
            $reporter->checkpoints,
            'Failed runs must still emit a terminal checkpoint event so dashboards can distinguish failed from stalled.',
        );
        $finalCheckpoint = $reporter->checkpoints[count($reporter->checkpoints) - 1];
        $this->assertSame(2, $finalCheckpoint['total']);
    }

    public function test_run_emits_final_checkpoint_for_empty_batch(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        $reporter = new RecordingBatchProgressReporter;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: $reporter,
        );

        // Empty/filtered runs must still emit the documented terminal
        // event so dashboards can distinguish a finished short run
        // from a stalled one.
        $outputs = $batch->run(
            samples: [],
            sampleInvocations: [],
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 2,
                queue: 'evals',
                timeoutSeconds: 5,
                checkpointEvery: 25,
            ),
        );

        $this->assertSame([], $outputs);
        $this->assertSame(
            [['samples_completed' => 0, 'total' => 0]],
            $reporter->checkpoints,
        );
    }

    public function test_run_completes_when_progress_reporter_throws(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: new ThrowingBatchProgressReporter,
        );

        $samples = $this->samples();

        // A logging or metrics reporter that throws at checkpoint time
        // must NOT abort the batch. The eval would otherwise flip green
        // runs to exit 1 because of unrelated telemetry trouble.
        $outputs = $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 2,
                queue: 'evals',
                timeoutSeconds: 5,
                checkpointEvery: 1,
            ),
        );

        $this->assertSame(['first output', 'second output'], $outputs);
    }

    public function test_run_emits_final_checkpoint_even_when_total_is_below_interval(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        $reporter = new RecordingBatchProgressReporter;

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: $reporter,
        );

        $samples = $this->samples();

        $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 2,
                queue: 'evals',
                timeoutSeconds: 5,
                checkpointEvery: 25,
            ),
        );

        $this->assertSame(
            [['samples_completed' => 2, 'total' => 2]],
            $reporter->checkpoints,
        );
    }

    public function test_run_aborts_chunk_when_rate_limit_pause_consumes_chunk_deadline(): void
    {
        // Pins both halves of the producer-side throttling/deadline path:
        //   1. run() actually consults `rateLimiter` — the rate-limit
        //      record/wait/cap math runs inside dispatchSampleJobs().
        //      Without it, every sample would dispatch in microseconds
        //      and the elapsed-time assertion below would fail.
        //   2. run() actually consults `chunkDeadlineMicrotime` — when
        //      the throttle pause would outlast the chunk deadline,
        //      dispatch aborts and the deadline-exceeded EvalRunException
        //      is raised. Without the deadline check, the producer
        //      would sleep for the full rate window past --batch-timeout.
        // Five samples are used so the deadline is definitively crossed
        // even with usleep timer slop on Windows: by the time
        // dispatchSampleJobs() returns, multiple capped pauses have
        // accumulated past the chunk deadline.
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            // 1-second chunk deadline. Sample 0 dispatches immediately
            // and runs synchronously. Subsequent samples each need ~60s
            // of throttle wait, capped by the deadline cap so the test
            // completes in ~1s — not 60s.
            defaultWaitTimeoutSeconds: 1,
        );

        $samples = [
            new DatasetSample(id: 's1', input: ['answer' => 'a'], expectedOutput: 'a'),
            new DatasetSample(id: 's2', input: ['answer' => 'b'], expectedOutput: 'b'),
            new DatasetSample(id: 's3', input: ['answer' => 'c'], expectedOutput: 'c'),
            new DatasetSample(id: 's4', input: ['answer' => 'd'], expectedOutput: 'd'),
            new DatasetSample(id: 's5', input: ['answer' => 'e'], expectedOutput: 'e'),
        ];

        $start = microtime(true);
        try {
            $batch->run(
                samples: $samples,
                sampleInvocations: $this->sampleInvocations($samples),
                runner: new LazyParallelAnswerRunner,
                options: BatchOptions::lazyParallel(
                    concurrency: 5,
                    queue: 'evals',
                    timeoutSeconds: 5,
                    rateLimit: 1,
                    rateWindowSeconds: 60,
                ),
            );
            $this->fail('Expected EvalRunException because the rate-limit pause consumed the chunk deadline.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString('chunk dispatch consumed the full', $e->getMessage());
            // The diagnostic must distinguish dispatched samples from
            // undispatched ones: with rateLimit=1 only the first sample
            // of the window can dispatch, so 4 of 5 must be reported as
            // still undispatched. Reporting the full chunk size (5)
            // would overstate the impact and mislead operators tuning
            // chunk size or rate limits.
            $this->assertMatchesRegularExpression(
                '/with [1-4] of 5 sample\(s\) still undispatched/',
                $e->getMessage(),
                'Diagnostic must report the count of undispatched samples, not the full chunk size.',
            );
        }
        $elapsed = microtime(true) - $start;

        // The deadline cap on throttleDispatch() must keep the test
        // bounded by the wait timeout (1s) plus small overhead — NOT
        // the full 60s rate window. A regression that drops the
        // remaining-deadline cap inside throttleDispatch() would stretch
        // this test to ~60s.
        $this->assertLessThan(
            10.0,
            $elapsed,
            sprintf('Rate-limit pause was not capped at the chunk deadline (elapsed %.2fs).', $elapsed),
        );
    }

    public function test_run_returns_outputs_when_chunk_completes_at_or_past_deadline(): void
    {
        // Pins the false-positive fix: with fast workers or the sync
        // queue driver, every sample in a window can finish inside the
        // budget yet the post-loop microtime() can still cross the
        // deadline by a few microseconds. The deadline check must NOT
        // flip a healthy run to a `0 of N undispatched` failure when
        // dispatch was actually complete — let waitForIndexedOutputs()
        // collect the already-stored outputs and return normally.
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            // 1-second wait timeout; the runner sleeps just over 1s so
            // the post-dispatch microtime() check definitively crosses
            // the deadline, but `dispatchedInWindow == count($window)`
            // because every sample was handed to the dispatcher.
            defaultWaitTimeoutSeconds: 1,
        );

        $samples = [
            new DatasetSample(id: 's1', input: ['answer' => 'first'], expectedOutput: 'first'),
            new DatasetSample(id: 's2', input: ['answer' => 'second'], expectedOutput: 'second'),
        ];

        $outputs = $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new SlowSyncSampleRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 2,
                queue: 'evals',
                timeoutSeconds: 5,
            ),
        );

        $this->assertSame(['first', 'second'], $outputs);
    }

    public function test_run_throttles_dispatch_under_low_rate_limit(): void
    {
        // Pins that run() consults `rateLimiter` for the dispatch pace.
        // With rateLimit=2 / rateWindowSeconds=1 and 4 samples, the
        // first 2 samples dispatch immediately and the next 2 must wait
        // ~1s for the rolling window to slide. Without rate-limit
        // throttling, all 4 would dispatch in microseconds.
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 30,
            // Generous wait timeout (10s) so the rate-limit pause has
            // budget to complete and the EvalRunException deadline path
            // does NOT fire — this test isolates the throttle behavior.
            defaultWaitTimeoutSeconds: 10,
        );

        $samples = [
            new DatasetSample(id: 's1', input: ['answer' => 'a'], expectedOutput: 'a'),
            new DatasetSample(id: 's2', input: ['answer' => 'b'], expectedOutput: 'b'),
            new DatasetSample(id: 's3', input: ['answer' => 'c'], expectedOutput: 'c'),
            new DatasetSample(id: 's4', input: ['answer' => 'd'], expectedOutput: 'd'),
        ];

        $start = microtime(true);
        $outputs = $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 4,
                queue: 'evals',
                timeoutSeconds: 5,
                rateLimit: 2,
                rateWindowSeconds: 1,
            ),
        );
        $elapsed = microtime(true) - $start;

        $this->assertSame(['a', 'b', 'c', 'd'], $outputs);

        // The third dispatch must wait until the oldest of the first
        // two timestamps slides out of the 1-second window, so total
        // elapsed has to exceed at least ~0.8s. Without the rate
        // limiter being consulted, the four sync runs would finish in
        // a handful of milliseconds.
        $this->assertGreaterThan(
            0.8,
            $elapsed,
            sprintf('Rate limiter was not consulted by run() (elapsed %.4fs).', $elapsed),
        );
    }

    public function test_run_completes_when_terminal_reporter_throws_on_success_path(): void
    {
        // Pins reportTerminal() exception tolerance on the success path.
        // A telemetry sink failure during the end-of-batch terminal
        // event must NOT abort an otherwise-healthy batch; the eval
        // would otherwise flip green runs to exit 1 because of unrelated
        // observer trouble.
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: new ThrowingTerminalProgressReporter,
        );

        $samples = $this->samples();

        $outputs = $batch->run(
            samples: $samples,
            sampleInvocations: $this->sampleInvocations($samples),
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 2,
                queue: 'evals',
                timeoutSeconds: 5,
                checkpointEvery: 1,
            ),
        );

        $this->assertSame(['first output', 'second output'], $outputs);
    }

    public function test_run_propagates_original_failure_when_terminal_reporter_throws_on_failure_path(): void
    {
        // Pins reportTerminal() exception tolerance on the failure path.
        // The original sample failure must propagate; the reporter's
        // exception must NOT mask it. A regression that lets the
        // observer exception escape would surface "telemetry sink
        // unavailable" instead of the actual sample-level error,
        // making operational debugging dramatically harder.
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: new ThrowingTerminalProgressReporter,
        );

        $samples = $this->samples();

        try {
            $batch->run(
                samples: $samples,
                sampleInvocations: $this->sampleInvocations($samples),
                runner: new LazyParallelFailingRunner,
                options: BatchOptions::lazyParallel(
                    concurrency: 2,
                    queue: 'evals',
                    timeoutSeconds: 5,
                    checkpointEvery: 1,
                ),
            );
            $this->fail('Expected the failing runner to surface as EvalRunException.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString('runner exploded', $e->getMessage());
            $this->assertStringNotContainsString(
                'telemetry sink unavailable',
                $e->getMessage(),
                'Observer/reporter exceptions must not mask the original sample failure.',
            );
        }
    }

    public function test_run_completes_for_empty_batch_when_terminal_reporter_throws(): void
    {
        // Pins reportTerminal() exception tolerance on the empty-batch
        // path. STATUS_EMPTY is emitted to terminal-aware reporters even
        // for zero-sample runs so dashboards can distinguish a finished
        // short run from a stalled one — but a throwing reporter must
        // not abort the empty-batch return path.
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->app->make(Dispatcher::class);
        /** @var BatchResultStore $resultStore */
        $resultStore = $this->app->make(BatchResultStore::class);
        $batch = new LazyParallelBatch(
            dispatcher: $dispatcher,
            resultStore: $resultStore,
            container: $this->app,
            resultTtlSeconds: 10,
            progressReporter: new ThrowingTerminalProgressReporter,
        );

        $outputs = $batch->run(
            samples: [],
            sampleInvocations: [],
            runner: new LazyParallelAnswerRunner,
            options: BatchOptions::lazyParallel(
                concurrency: 2,
                queue: 'evals',
                timeoutSeconds: 5,
                checkpointEvery: 25,
            ),
        );

        $this->assertSame([], $outputs);
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

final class SlowSyncSampleRunner implements SampleRunner
{
    // Hardcoded sleep duration (constructor parameters with scalar
    // types are rejected by LazyParallelBatch's runner validation
    // because queue workers cannot reproduce caller-supplied state).
    public const SLEEP_MICROSECONDS = 600_000;

    public function run(SampleInvocation $sample): string
    {
        usleep(self::SLEEP_MICROSECONDS);

        return (string) $sample->input['answer'];
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

final class FailureBeforeLaterDispatchThrowsDispatcher implements Dispatcher
{
    public function __construct(
        private readonly RecordingBatchResultStore $store,
    ) {}

    public function dispatch($command): mixed
    {
        if (! $command instanceof EvaluateSampleJob) {
            return null;
        }

        if ($command->index === 0) {
            $this->store->recordFailure(
                batchId: $command->batchId,
                index: $command->index,
                sampleId: $command->sampleId,
                error: 'first failed',
                ttlSeconds: $command->resultTtlSeconds,
            );

            return null;
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

final class CompleteAfterMissingOutputReadStore implements BatchResultStore
{
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
            return [];
        }

        return [
            0 => ['sample_id' => 's1', 'actual_output' => 'first output'],
        ];
    }

    public function failures(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        return [];
    }
}

final class CompleteAfterTimeoutMissingOutputReadStore implements BatchResultStore
{
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

            return [];
        }

        return [
            0 => ['sample_id' => 's1', 'actual_output' => 'first output'],
        ];
    }

    public function failures(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        return [];
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

final class RecordingBatchProgressReporter implements BatchProgressReporter
{
    /** @var list<array{samples_completed: int, total: int}> */
    public array $checkpoints = [];

    public function reportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void
    {
        $this->checkpoints[] = [
            'samples_completed' => $samplesCompleted,
            'total' => $totalSamples,
        ];
    }
}

final class ThrowingBatchProgressReporter implements BatchProgressReporter
{
    public function reportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void
    {
        throw new \RuntimeException('telemetry sink unavailable');
    }
}

final class RecordingTerminalProgressReporter implements BatchTerminalProgressReporter
{
    /** @var list<array{samples_completed: int, total: int}> */
    public array $checkpoints = [];

    /** @var list<array{status: string, samples_completed: int, total: int}> */
    public array $terminalEvents = [];

    public function reportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void
    {
        $this->checkpoints[] = [
            'samples_completed' => $samplesCompleted,
            'total' => $totalSamples,
        ];
    }

    public function reportTerminal(string $batchId, int $samplesCompleted, int $totalSamples, string $status): void
    {
        $this->terminalEvents[] = [
            'status' => $status,
            'samples_completed' => $samplesCompleted,
            'total' => $totalSamples,
        ];
    }
}

final class ThrowingTerminalProgressReporter implements BatchTerminalProgressReporter
{
    public function reportCheckpoint(string $batchId, int $samplesCompleted, int $totalSamples): void
    {
        // Checkpoint failures are pinned separately by
        // ThrowingBatchProgressReporter / test_run_completes_when_progress_reporter_throws.
        // This double only throws on the terminal callback so the
        // tests target reportTerminal() exception tolerance specifically.
    }

    public function reportTerminal(string $batchId, int $samplesCompleted, int $totalSamples, string $status): void
    {
        throw new \RuntimeException('telemetry sink unavailable');
    }
}
