<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Jobs;

use Padosoft\EvalHarness\Batches\BatchResultStore;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
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
