<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Tests\TestCase;

final class EvalEngineRepetitionsTest extends TestCase
{
    public function test_default_is_one_execution_per_sample(): void
    {
        $engine = $this->engine();
        $calls = 0;

        $this->registerDataset($engine, 'rep.default');

        $report = $engine->run('rep.default', function (array $input) use (&$calls): string {
            $calls++;

            return '4';
        });

        $this->assertSame(2, $calls);
        $this->assertSame(1, $report->repetitions());
        $this->assertSame(2, $report->totalSamples());
        $this->assertSame(2, $report->totalExecutions());
    }

    public function test_repetitions_execute_the_system_under_test_repeatedly(): void
    {
        $engine = $this->engine();
        $calls = 0;

        $this->registerDataset($engine, 'rep.explicit');

        $report = $engine->run('rep.explicit', function (array $input) use (&$calls): string {
            $calls++;

            return '4';
        }, repetitions: 3);

        $this->assertSame(6, $calls);
        $this->assertSame(3, $report->repetitions());
        $this->assertSame(2, $report->totalSamples());
        $this->assertSame(6, $report->totalExecutions());
    }

    /**
     * The point of the whole feature: a pipeline that answers correctly two
     * times out of three is not a pipeline that scores 1.0, and one execution
     * cannot tell those apart.
     */
    public function test_a_flapping_pipeline_is_reported_as_unstable(): void
    {
        $engine = $this->engine();
        $call = 0;

        $engine->dataset('rep.flaky')
            ->withSamples([new DatasetSample(id: 's1', input: ['q' => '2+2'], expectedOutput: '4')])
            ->withMetrics(['exact-match'])
            ->register();

        $report = $engine->run('rep.flaky', function () use (&$call): string {
            $call++;

            return $call === 2 ? 'five' : '4';
        }, repetitions: 3);

        $aggregate = $report->sampleAggregates()[0];

        $this->assertSame(3, $aggregate->repetitions);
        $this->assertSame(2, $aggregate->passed);
        $this->assertTrue($aggregate->isUnstable());
        $this->assertGreaterThan(0.0, $aggregate->scoreStddev);
        $this->assertFalse($report->precision()['target_resolvable']);
    }

    public function test_repetitions_are_recorded_on_each_execution(): void
    {
        $engine = $this->engine();
        $this->registerDataset($engine, 'rep.indexes');

        $report = $engine->run('rep.indexes', static fn (): string => '4', repetitions: 2);

        $indexes = array_map(
            static fn ($result): int => $result->repetition,
            $report->sampleResults,
        );

        $this->assertSame([0, 0, 1, 1], $indexes);
    }

    public function test_dataset_yaml_can_declare_repetitions(): void
    {
        $engine = $this->engine();

        $dataset = $engine->dataset('rep.from-yaml')
            ->loadFromYamlString(<<<'YAML'
            name: rep.from-yaml
            repetitions: 4
            samples:
              - id: s1
                input:
                  q: "2+2"
                expected_output: "4"
            YAML)
            ->withMetrics(['exact-match'])
            ->register();

        $this->assertSame(4, $dataset->repetitions);
        $this->assertSame(4, $dataset->executionCount());

        $calls = 0;
        $engine->run('rep.from-yaml', function () use (&$calls): string {
            $calls++;

            return '4';
        });

        $this->assertSame(4, $calls);
    }

    public function test_builder_repetitions_win_over_the_yaml_field(): void
    {
        $engine = $this->engine();

        $dataset = $engine->dataset('rep.override')
            ->loadFromYamlString(<<<'YAML'
            name: rep.override
            repetitions: 4
            samples:
              - id: s1
                input:
                  q: "2+2"
                expected_output: "4"
            YAML)
            ->withRepetitions(2)
            ->withMetrics(['exact-match'])
            ->register();

        $this->assertSame(2, $dataset->repetitions);
    }

    public function test_run_argument_wins_over_the_dataset_setting(): void
    {
        $engine = $this->engine();

        $engine->dataset('rep.argument')
            ->withSamples([new DatasetSample(id: 's1', input: ['q' => '2+2'], expectedOutput: '4')])
            ->withRepetitions(5)
            ->withMetrics(['exact-match'])
            ->register();

        $calls = 0;
        $report = $engine->run('rep.argument', function () use (&$calls): string {
            $calls++;

            return '4';
        }, repetitions: 2);

        $this->assertSame(2, $calls);
        $this->assertSame(2, $report->repetitions());
    }

    public function test_invalid_repetitions_are_rejected(): void
    {
        $engine = $this->engine();

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('at least 1');

        $engine->dataset('rep.invalid')
            ->withSamples([new DatasetSample(id: 's1', input: ['q' => 'x'], expectedOutput: 'y')])
            ->withRepetitions(0);
    }

    public function test_yaml_rejects_a_non_integer_repetitions_field(): void
    {
        $engine = $this->engine();

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("'repetitions' must be an integer");

        $engine->dataset('rep.bad-yaml')->loadFromYamlString(<<<'YAML'
        name: rep.bad-yaml
        repetitions: "many"
        samples:
          - id: s1
            input:
              q: "2+2"
            expected_output: "4"
        YAML);
    }

    /**
     * Repeating a scoring pass over fixed outputs measures the metrics rather
     * than the pipeline — the cheapest judge-stability check in the package.
     */
    public function test_saved_outputs_can_be_scored_repeatedly(): void
    {
        $engine = $this->engine();
        $this->registerDataset($engine, 'rep.outputs');

        $report = $engine->scoreOutputs('rep.outputs', ['s1' => '4', 's2' => '6'], repetitions: 3);

        $this->assertSame(3, $report->repetitions());
        $this->assertSame(2, $report->totalSamples());
        $this->assertSame(6, $report->totalExecutions());

        // Deterministic metrics over fixed outputs: zero spread by construction.
        foreach ($report->sampleAggregates() as $aggregate) {
            $this->assertSame(0.0, $aggregate->scoreStddev);
            $this->assertFalse($aggregate->isUnstable());
        }
    }

    /**
     * The CLI, the YAML loader, the builder and GoldenDataset all refuse a
     * non-positive value; a programmatic caller quietly getting one execution
     * out of `repetitions: 0` would spend the tokens and produce a report that
     * hides its own misconfiguration.
     */
    public function test_a_non_positive_run_argument_is_rejected(): void
    {
        $engine = $this->engine();
        $this->registerDataset($engine, 'rep.reject');

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Repetitions must be at least 1');

        $engine->run('rep.reject', static fn (): string => '4', repetitions: 0);
    }

    public function test_a_negative_run_argument_is_rejected(): void
    {
        $engine = $this->engine();
        $this->registerDataset($engine, 'rep.reject.negative');

        $this->expectException(EvalRunException::class);

        $engine->scoreOutputs('rep.reject.negative', ['s1' => '4', 's2' => '6'], repetitions: -3);
    }

    private function engine(): EvalEngine
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        return $engine;
    }

    private function registerDataset(EvalEngine $engine, string $name): void
    {
        $engine->dataset($name)
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => '2+2'], expectedOutput: '4'),
                new DatasetSample(id: 's2', input: ['q' => '3+3'], expectedOutput: '6'),
            ])
            ->withMetrics(['exact-match'])
            ->register();
    }
}
