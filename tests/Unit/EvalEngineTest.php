<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit;

use Closure;
use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
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

    public function test_run_accepts_sample_runner_contract(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.runner.contract')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '2+2'], expectedOutput: '4'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $runner = new class implements SampleRunner
        {
            public function run(SampleInvocation $sample): string
            {
                return $sample->input['q'] === '2+2' ? '4' : '';
            }
        };

        $report = $engine->run('rag.runner.contract', $runner);

        $this->assertSame(1, $report->totalSamples());
        $this->assertSame(1.0, $report->meanScore('exact-match'));
    }

    public function test_run_routes_sample_runner_method_reference_to_runner_contract(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.runner.method-reference')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '3+3'], expectedOutput: '6'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $runner = new class implements SampleRunner
        {
            public function run(SampleInvocation $sample): string
            {
                return $sample->id === 's1' && $sample->input['q'] === '3+3' ? '6' : '';
            }
        };

        $report = $engine->run('rag.runner.method-reference', [$runner, 'run']);

        $this->assertSame(1.0, $report->meanScore('exact-match'));
    }

    public function test_run_routes_plain_array_callable_typed_as_sample_invocation(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.runner.plain-array-callable')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '10+10'], expectedOutput: '20'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $callable = new class
        {
            public function answer(SampleInvocation $sample): string
            {
                return $sample->id === 's1' && $sample->input['q'] === '10+10' ? '20' : '';
            }
        };

        $report = $engine->run('rag.runner.plain-array-callable', [$callable, 'answer']);

        $this->assertSame(1.0, $report->meanScore('exact-match'));
    }

    public function test_run_routes_first_class_runner_callable_to_runner_contract(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.runner.first-class-callable')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '4+4'], expectedOutput: '8'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $runner = new class implements SampleRunner
        {
            public function run(SampleInvocation $sample): string
            {
                return $sample->id === 's1' && $sample->input['q'] === '4+4' ? '8' : '';
            }
        };

        $firstClassCallable = $runner->run(...);
        $fromCallable = Closure::fromCallable([$runner, 'run']);

        $this->assertSame(1.0, $engine->run('rag.runner.first-class-callable', $firstClassCallable)->meanScore('exact-match'));
        $this->assertSame(1.0, $engine->run('rag.runner.first-class-callable', $fromCallable)->meanScore('exact-match'));
    }

    public function test_legacy_callable_still_receives_input_array(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.runner.legacy-callable')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '5+5'], expectedOutput: '10'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $report = $engine->run(
            'rag.runner.legacy-callable',
            static fn (array $input): string => $input['q'] === '5+5' ? '10' : '',
        );

        $this->assertSame(1.0, $report->meanScore('exact-match'));
    }

    public function test_run_routes_string_callable_typed_as_sample_invocation(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.runner.string-callable')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '6+6'], expectedOutput: '12'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $report = $engine->run('rag.runner.string-callable', __NAMESPACE__.'\\sample_invocation_string_runner');

        $this->assertSame(1.0, $report->meanScore('exact-match'));
    }

    public function test_run_routes_static_method_string_callable_typed_as_sample_invocation(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.runner.static-callable')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '8+8'], expectedOutput: '16'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $report = $engine->run('rag.runner.static-callable', StaticSampleInvocationCallable::class.'::run');

        $this->assertSame(1.0, $report->meanScore('exact-match'));
    }

    public function test_run_routes_union_typed_callable_when_sample_invocation_is_allowed(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.runner.union-callable')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '9+9'], expectedOutput: '18'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $callable = static function (SampleInvocation|array $sample): string {
            if (! $sample instanceof SampleInvocation) {
                return '';
            }

            return $sample->id === 's1' && $sample->input['q'] === '9+9' ? '18' : '';
        };

        $report = $engine->run('rag.runner.union-callable', $callable);

        $this->assertSame(1.0, $report->meanScore('exact-match'));
    }

    public function test_sample_invocation_validation_fails_before_runner_receives_any_sample(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.runner.fail-fast')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '1+1'], expectedOutput: '2'),
                new DatasetSample(id: 's2', input: ['bad' => new \stdClass], expectedOutput: 'x'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $runner = new class implements SampleRunner
        {
            public int $calls = 0;

            public function run(SampleInvocation $sample): string
            {
                $this->calls++;

                return '2';
            }
        };

        try {
            $engine->run('rag.runner.fail-fast', $runner);
            $this->fail('Expected SampleInvocation validation to fail before running samples.');
        } catch (EvalRunException $e) {
            $this->assertStringContainsString('must be queue-serializable', $e->getMessage());
        }

        $this->assertSame(0, $runner->calls);
    }

    public function test_run_routes_invokable_object_typed_as_sample_invocation(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset('rag.runner.invokable-callable')
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '7+7'], expectedOutput: '14'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $callable = new class
        {
            public function __invoke(SampleInvocation $sample): string
            {
                return $sample->id === 's1' && $sample->input['q'] === '7+7' ? '14' : '';
            }
        };

        $report = $engine->run('rag.runner.invokable-callable', $callable);

        $this->assertSame(1.0, $report->meanScore('exact-match'));
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

function sample_invocation_string_runner(SampleInvocation $sample): string
{
    return $sample->id === 's1' && $sample->input['q'] === '6+6' ? '12' : '';
}

final class StaticSampleInvocationCallable
{
    public static function run(SampleInvocation $sample): string
    {
        return $sample->id === 's1' && $sample->input['q'] === '8+8' ? '16' : '';
    }
}
