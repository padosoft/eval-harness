<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\EvalSets;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\EvalSets\EvalSetDefinition;
use Padosoft\EvalHarness\EvalSets\EvalSetManifest;
use Padosoft\EvalHarness\EvalSets\EvalSetManifestEntry;
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

    private function registerDataset(EvalEngine $engine, string $name, string $answer): void
    {
        $engine->dataset($name)
            ->withSamples([new DatasetSample(id: $answer, input: ['answer' => $answer], expectedOutput: $answer)])
            ->withMetrics(['exact-match'])
            ->register();
    }
}
