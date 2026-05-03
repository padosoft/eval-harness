<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\EvalSets;

use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Batches\LazyParallelBatch;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\EvalSets\EvalSetDefinition;
use Padosoft\EvalHarness\EvalSets\EvalSetManifest;
use Padosoft\EvalHarness\EvalSets\EvalSetManifestEntry;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Tests\TestCase;

final class EvalSetRunnerTest extends TestCase
{
    public function test_engine_runs_eval_set_and_records_completed_manifest(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $this->registerDataset($engine, 'rag.first', 'first');
        $this->registerDataset($engine, 'rag.second', 'second');

        $result = $engine->runEvalSet(
            $engine->evalSet('nightly', ['rag.first', 'rag.second']),
            static fn (array $input): string => (string) $input['answer'],
        );

        $this->assertTrue($result->isComplete());
        $this->assertSame(['rag.first', 'rag.second'], $result->completedDatasetNames());
        $this->assertSame([], $result->failedDatasetNames());
        $this->assertCount(2, $result->reports);
        $this->assertSame(1.0, $result->reportFor('rag.second')?->meanScore('exact-match'));
        $this->assertSame(EvalSetManifestEntry::STATUS_COMPLETED, $result->manifest->statusFor('rag.first'));
    }

    public function test_engine_resumes_eval_set_by_skipping_completed_datasets(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $this->registerDataset($engine, 'rag.first', 'first');
        $this->registerDataset($engine, 'rag.second', 'second');

        $definition = new EvalSetDefinition('nightly', ['rag.first', 'rag.second']);
        $completedReport = $engine->run('rag.first', static fn (array $input): string => (string) $input['answer']);
        $resumeManifest = EvalSetManifest::start($definition, 1.0)
            ->markRunning('rag.first', 1.0)
            ->markCompleted('rag.first', $completedReport);

        $calls = [];
        $result = $engine->runEvalSet(
            $definition,
            static function (array $input) use (&$calls): string {
                $calls[] = $input['answer'];

                return (string) $input['answer'];
            },
            manifest: $resumeManifest,
        );

        $this->assertSame(['second'], $calls);
        $this->assertTrue($result->isComplete());
        $this->assertCount(1, $result->reports);
        $this->assertNull($result->reportFor('rag.first'));
        $this->assertSame(1.0, $result->reportFor('rag.second')?->meanScore('exact-match'));
    }

    public function test_engine_runs_eval_set_through_lazy_parallel_sync_queue(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $this->registerDataset($engine, 'rag.first', 'first');
        $this->registerDataset($engine, 'rag.second', 'second');

        $result = $engine->runEvalSet(
            new EvalSetDefinition('nightly', ['rag.first', 'rag.second']),
            new EvalSetAnswerRunner,
            BatchOptions::lazyParallel(concurrency: 2, queue: 'evals', timeoutSeconds: 5, waitTimeoutSeconds: 5),
        );

        $this->assertTrue($result->isComplete());
        $this->assertCount(2, $result->reports);
        $this->assertSame(1.0, $result->reportFor('rag.first')?->meanScore('exact-match'));
        $this->assertSame(1.0, $result->reportFor('rag.second')?->meanScore('exact-match'));
    }

    public function test_engine_returns_failed_manifest_without_running_later_datasets(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $this->registerDataset($engine, 'rag.first', 'first');
        $this->registerDataset($engine, 'rag.second', 'second');
        $this->registerDataset($engine, 'rag.third', 'third');

        $calls = [];
        $result = $engine->runEvalSet(
            new EvalSetDefinition('nightly', ['rag.first', 'rag.second', 'rag.third']),
            static function (array $input) use (&$calls): string {
                $calls[] = $input['answer'];
                if ($input['answer'] === 'second') {
                    throw new \RuntimeException('runner crashed');
                }

                return (string) $input['answer'];
            },
        );

        $this->assertSame(['first', 'second'], $calls);
        $this->assertFalse($result->isComplete());
        $this->assertSame(['rag.first'], $result->completedDatasetNames());
        $this->assertSame(['rag.second'], $result->failedDatasetNames());
        $this->assertSame(EvalSetManifestEntry::STATUS_PENDING, $result->manifest->statusFor('rag.third'));
        $this->assertSame('runner crashed', $result->manifest->entryFor('rag.second')?->error);
        $this->assertCount(1, $result->reports);
    }

    public function test_engine_stops_when_resuming_manifest_that_already_has_failed_dataset(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $this->registerDataset($engine, 'rag.first', 'first');
        $this->registerDataset($engine, 'rag.second', 'second');

        $definition = new EvalSetDefinition('nightly', ['rag.first', 'rag.second']);
        $failedManifest = EvalSetManifest::start($definition, 1.0)
            ->markRunning('rag.first', 1.0)
            ->markFailed('rag.first', 'previous failure', 2.0);

        $calls = 0;
        $result = $engine->runEvalSet(
            $definition,
            static function (array $input) use (&$calls): string {
                $calls++;

                return (string) $input['answer'];
            },
            manifest: $failedManifest,
        );

        $this->assertSame(0, $calls);
        $this->assertFalse($result->isComplete());
        $this->assertSame(['rag.first'], $result->failedDatasetNames());
        $this->assertSame('previous failure', $result->manifest->entryFor('rag.first')?->error);
    }

    public function test_engine_surfaces_unregistered_eval_set_dataset_as_setup_error(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Dataset 'rag.missing' is not registered");

        $engine->runEvalSet(
            new EvalSetDefinition('nightly', ['rag.missing']),
            static fn (array $input): string => (string) $input['answer'],
        );
    }

    public function test_engine_surfaces_lazy_parallel_callable_sut_as_setup_error(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $this->registerDataset($engine, 'rag.first', 'first');

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('requires a SampleRunner system-under-test');

        $engine->runEvalSet(
            new EvalSetDefinition('nightly', ['rag.first']),
            static fn (array $input): string => (string) $input['answer'],
            BatchOptions::lazyParallel(),
        );
    }

    public function test_engine_surfaces_lazy_parallel_service_resolution_errors_as_setup_errors(): void
    {
        $this->app->bind(LazyParallelBatch::class, static function (): never {
            throw new \RuntimeException('invalid cache store [missing]');
        });

        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $this->registerDataset($engine, 'rag.first', 'first');

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Failed to resolve lazy parallel batch services: invalid cache store [missing]');

        $engine->runEvalSet(
            new EvalSetDefinition('nightly', ['rag.first']),
            new EvalSetAnswerRunner,
            BatchOptions::lazyParallel(),
        );
    }

    public function test_engine_surfaces_lazy_parallel_sample_invocation_errors_as_setup_errors(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('rag.first')
            ->withSamples([new DatasetSample(id: 's1', input: ['bad' => new \stdClass], expectedOutput: 'x')])
            ->withMetrics(['exact-match'])
            ->register();

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must be queue-serializable');

        $engine->runEvalSet(
            new EvalSetDefinition('nightly', ['rag.first']),
            new EvalSetAnswerRunner,
            BatchOptions::lazyParallel(),
        );
    }

    public function test_engine_skips_completed_unregistered_dataset_when_resuming_pending_suffix(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $this->registerDataset($engine, 'rag.second', 'second');

        $definition = new EvalSetDefinition('nightly', ['rag.first', 'rag.second']);
        $completedReport = new EvalReport(
            datasetName: 'rag.first',
            sampleResults: [],
            failures: [],
            startedAt: 1.0,
            finishedAt: 2.0,
        );
        $manifest = EvalSetManifest::start($definition, 1.0)
            ->markRunning('rag.first', 1.0)
            ->markCompleted('rag.first', $completedReport);

        $calls = [];
        $result = $engine->runEvalSet(
            $definition,
            static function (array $input) use (&$calls): string {
                $calls[] = $input['answer'];

                return (string) $input['answer'];
            },
            manifest: $manifest,
        );

        $this->assertSame(['second'], $calls);
        $this->assertTrue($result->isComplete());
        $this->assertCount(1, $result->reports);
        $this->assertNull($result->reportFor('rag.first'));
    }

    public function test_engine_returns_existing_failed_manifest_without_lazy_parallel_sut_preflight(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $definition = new EvalSetDefinition('nightly', ['rag.first']);
        $manifest = EvalSetManifest::start($definition, 1.0)
            ->markRunning('rag.first', 1.0)
            ->markFailed('rag.first', 'already failed', 2.0);

        $result = $engine->runEvalSet(
            $definition,
            static fn (array $input): string => (string) $input['answer'],
            BatchOptions::lazyParallel(),
            $manifest,
        );

        $this->assertFalse($result->isComplete());
        $this->assertSame(['rag.first'], $result->failedDatasetNames());
        $this->assertSame('already failed', $result->manifest->entryFor('rag.first')?->error);
    }

    private function registerDataset(EvalEngine $engine, string $name, string $answer): void
    {
        $engine->dataset($name)
            ->withSamples([new DatasetSample(id: $answer, input: ['answer' => $answer], expectedOutput: $answer)])
            ->withMetrics(['exact-match'])
            ->register();
    }
}

final class EvalSetAnswerRunner implements SampleRunner
{
    public function run(SampleInvocation $sample): string
    {
        return (string) $sample->input['answer'];
    }
}
