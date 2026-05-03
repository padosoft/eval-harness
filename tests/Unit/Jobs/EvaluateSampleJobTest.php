<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Jobs;

use Padosoft\EvalHarness\Batches\BatchResultStore;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Jobs\EvaluateSampleJob;
use Padosoft\EvalHarness\Tests\TestCase;

final class EvaluateSampleJobTest extends TestCase
{
    public function test_handle_leaves_runner_failures_for_queue_retry_and_failed_reporting(): void
    {
        $store = new JobRecordingBatchResultStore;
        $job = $this->job(JobFailingRunner::class);

        try {
            $job->handle($this->app, $store);

            $this->fail('Expected runner failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('transient runner failure', $e->getMessage());
        }

        $this->assertSame([], $store->failures('batch-1', 1));
    }

    public function test_failed_hook_records_queue_level_failures(): void
    {
        $store = new JobRecordingBatchResultStore;
        $this->app->instance(BatchResultStore::class, $store);

        $job = $this->job(JobAnswerRunner::class);
        $job->failed(new \RuntimeException('worker timed out'));

        $this->assertSame([
            0 => ['sample_id' => 's1', 'error' => 'worker timed out'],
        ], $store->failures('batch-1', 1));
        $this->assertTrue($job->failOnTimeout);
    }

    public function test_failed_hook_wraps_result_store_errors_with_original_failure(): void
    {
        $this->app->instance(BatchResultStore::class, new ThrowingFailureBatchResultStore);
        $job = $this->job(JobAnswerRunner::class);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Failed to record lazy parallel batch failure for sample 's1'");
        $this->expectExceptionMessage('worker timed out');
        $this->expectExceptionMessage('cache write failed');

        $job->failed(new \RuntimeException('worker timed out'));
    }

    public function test_handle_rejects_container_misbound_runner_class(): void
    {
        $store = new JobRecordingBatchResultStore;
        $this->app->bind(JobAnswerRunner::class, static fn (): object => new \stdClass);
        $job = $this->job(JobAnswerRunner::class);

        try {
            $job->handle($this->app, $store);

            $this->fail('Expected misbound runner rejection.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString("Queued sample runner '".JobAnswerRunner::class."' must resolve to", $e->getMessage());
        }

        $this->assertSame([], $store->successfulResults('batch-1', 1));
    }

    public function test_handle_rejects_instance_bound_runner_class(): void
    {
        $store = new JobRecordingBatchResultStore;
        $this->app->instance(JobInstanceBoundRunner::class, new JobInstanceBoundRunner);
        $job = $this->job(JobInstanceBoundRunner::class);

        try {
            $job->handle($this->app, $store);

            $this->fail('Expected instance-bound runner rejection.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString('must resolve to a fresh '.SampleRunner::class.' instance', $e->getMessage());
        }

        $this->assertSame([], $store->successfulResults('batch-1', 1));
    }

    public function test_handle_caches_fresh_runner_validation_per_runner_class(): void
    {
        $store = new JobRecordingBatchResultStore;
        $makeCount = 0;
        $this->app->bind(JobCountingRunner::class, static function () use (&$makeCount): JobCountingRunner {
            $makeCount++;

            return new JobCountingRunner;
        });

        $this->job(JobCountingRunner::class)->handle($this->app, $store);
        $this->job(JobCountingRunner::class)->handle($this->app, $store);

        $this->assertSame(3, $makeCount);
        $this->assertSame([
            0 => ['sample_id' => 's1', 'actual_output' => 'ok'],
        ], $store->successfulResults('batch-1', 1));
    }

    public function test_constructor_rejects_invalid_timeout_seconds(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Queued sample timeout must be null or greater than or equal to 1 second');

        new EvaluateSampleJob(
            batchId: 'batch-1',
            index: 0,
            sampleId: 's1',
            sample: new SampleInvocation(id: 's1', input: ['answer' => 'ok']),
            runnerClass: JobAnswerRunner::class,
            resultTtlSeconds: 60,
            timeoutSeconds: 0,
        );
    }

    public function test_constructor_rejects_mismatched_sample_id(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Queued sample id 'other' must match SampleInvocation id 's1'");

        new EvaluateSampleJob(
            batchId: 'batch-1',
            index: 0,
            sampleId: 'other',
            sample: new SampleInvocation(id: 's1', input: ['answer' => 'ok']),
            runnerClass: JobAnswerRunner::class,
            resultTtlSeconds: 60,
            timeoutSeconds: 30,
        );
    }

    public function test_constructor_rejects_anonymous_runner_classes(): void
    {
        $runner = new class implements SampleRunner
        {
            public function run(SampleInvocation $sample): string
            {
                return (string) $sample->input['answer'];
            }
        };

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must be a concrete, autoloadable SampleRunner class');

        new EvaluateSampleJob(
            batchId: 'batch-1',
            index: 0,
            sampleId: 's1',
            sample: new SampleInvocation(id: 's1', input: ['answer' => 'ok']),
            runnerClass: $runner::class,
            resultTtlSeconds: 60,
            timeoutSeconds: 30,
        );
    }

    public function test_constructor_rejects_non_instantiable_runner_classes(): void
    {
        /** @var class-string<SampleRunner> $runnerClass */
        $runnerClass = JobRunnerContract::class;

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must be a concrete, instantiable SampleRunner class');

        new EvaluateSampleJob(
            batchId: 'batch-1',
            index: 0,
            sampleId: 's1',
            sample: new SampleInvocation(id: 's1', input: ['answer' => 'ok']),
            runnerClass: $runnerClass,
            resultTtlSeconds: 60,
            timeoutSeconds: 30,
        );
    }

    /**
     * @param  class-string<SampleRunner>  $runnerClass
     */
    private function job(string $runnerClass): EvaluateSampleJob
    {
        return new EvaluateSampleJob(
            batchId: 'batch-1',
            index: 0,
            sampleId: 's1',
            sample: new SampleInvocation(id: 's1', input: ['answer' => 'ok']),
            runnerClass: $runnerClass,
            resultTtlSeconds: 60,
            timeoutSeconds: 30,
        );
    }
}

final class JobAnswerRunner implements SampleRunner
{
    public function run(SampleInvocation $sample): string
    {
        return (string) $sample->input['answer'];
    }
}

final class JobFailingRunner implements SampleRunner
{
    public function run(SampleInvocation $sample): string
    {
        throw new \RuntimeException('transient runner failure');
    }
}

final class JobInstanceBoundRunner implements SampleRunner
{
    public function run(SampleInvocation $sample): string
    {
        return 'stateful';
    }
}

final class JobCountingRunner implements SampleRunner
{
    public function run(SampleInvocation $sample): string
    {
        return 'ok';
    }
}

interface JobRunnerContract extends SampleRunner
{
    //
}

final class JobRecordingBatchResultStore implements BatchResultStore
{
    /** @var array<int, array{sample_id: string, actual_output: string}> */
    private array $outputs = [];

    /** @var array<int, array{sample_id: string, error: string}> */
    private array $failures = [];

    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void
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

    public function finish(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        $this->outputs = [];
        $this->failures = [];
    }

    public function abort(string $batchId, int $sampleCount, int $ttlSeconds): void
    {
        $this->outputs = [];
        $this->failures = [];
    }

    public function recordSuccess(string $batchId, int $index, string $sampleId, string $actualOutput, int $ttlSeconds): void
    {
        $this->outputs[$index] = ['sample_id' => $sampleId, 'actual_output' => $actualOutput];
    }

    public function recordFailure(string $batchId, int $index, string $sampleId, string $error, int $ttlSeconds): void
    {
        $this->failures[$index] = ['sample_id' => $sampleId, 'error' => $error];
    }

    public function successfulResults(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        if ($indexes === null) {
            return $this->outputs;
        }

        return array_intersect_key($this->outputs, array_flip($indexes));
    }

    public function failures(string $batchId, int $sampleCount, ?array $indexes = null): array
    {
        if ($indexes === null) {
            return $this->failures;
        }

        return array_intersect_key($this->failures, array_flip($indexes));
    }
}

final class ThrowingFailureBatchResultStore implements BatchResultStore
{
    public function start(string $batchId, int $sampleCount, int $ttlSeconds): void
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
        throw new \RuntimeException('cache write failed');
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
