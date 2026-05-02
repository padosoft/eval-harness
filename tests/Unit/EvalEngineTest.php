<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit;

use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Facades\EvalFacade;
use Padosoft\EvalHarness\Tests\TestCase;

final class EvalEngineTest extends TestCase
{
    public function test_run_unknown_dataset_throws(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('not registered');

        $engine->run('does.not.exist', fn () => 'x');
    }

    public function test_register_and_run_with_exact_match(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.engine.test')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '2+2'], expectedOutput: '4'),
                new DatasetSample(id: 's2', input: ['q' => '3+3'], expectedOutput: '6'),
                new DatasetSample(id: 's3', input: ['q' => '5+5'], expectedOutput: '10'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $report = $engine->run('rag.engine.test', static function (array $input): string {
            return match ($input['q']) {
                '2+2' => '4',     // exact match
                '3+3' => '6',     // exact match
                '5+5' => 'ten',   // miss
                default => '',
            };
        });

        $this->assertSame(3, $report->totalSamples());
        $this->assertSame(0, $report->totalFailures());
        $this->assertEqualsWithDelta(2.0 / 3.0, $report->meanScore('exact-match'), 1e-9);
        $this->assertEqualsWithDelta(2.0 / 3.0, $report->macroF1('exact-match'), 1e-9);
    }

    public function test_metric_failure_is_captured_not_thrown(): void
    {
        Http::fake([
            '*' => Http::response('boom', 500),
        ]);

        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.failing.judge')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['question' => 'q'], expectedOutput: 'e'),
            ])
            ->withMetrics(['llm-as-judge'])
            ->register();

        $report = $engine->run('rag.failing.judge', fn () => 'a');

        $this->assertSame(1, $report->totalSamples());
        $this->assertSame(1, $report->totalFailures());
        $this->assertSame('s1', $report->failures[0]->sampleId);
        $this->assertSame('llm-as-judge', $report->failures[0]->metricName);
    }

    public function test_sut_must_return_string(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.sut-non-string')
            ->withSamples([
                new DatasetSample(id: 's1', input: [], expectedOutput: 'x'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must return a string');

        /** @phpstan-ignore-next-line — deliberately wrong return type */
        $engine->run('rag.sut-non-string', fn () => 42);
    }

    public function test_reset_clears_registry(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.reset')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'x')])
            ->withMetrics(['exact-match'])
            ->register();

        $this->assertTrue($engine->hasDataset('rag.reset'));
        $engine->reset();
        $this->assertFalse($engine->hasDataset('rag.reset'));
        $this->assertSame([], $engine->registeredDatasetNames());
    }

    public function test_facade_proxies_to_engine(): void
    {
        EvalFacade::reset();
        EvalFacade::dataset('facade.test')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'x')])
            ->withMetrics(['exact-match'])
            ->register();

        $this->assertTrue(EvalFacade::hasDataset('facade.test'));
        $report = EvalFacade::run('facade.test', fn () => 'x');
        $this->assertSame(1.0, $report->meanScore('exact-match'));
    }
}
