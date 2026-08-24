<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Metrics\Trajectory\ToolCalledMetric;
use Padosoft\EvalHarness\Outputs\SavedOutputs;
use Padosoft\EvalHarness\Tests\TestCase;
use Padosoft\EvalHarness\Trajectory\ToolCall;
use Padosoft\EvalHarness\Trajectory\Trajectory;
use Padosoft\EvalHarness\Trajectory\TrajectoryRecorder;

final class EvalEngineTrajectoryTest extends TestCase
{
    public function test_every_trajectory_alias_resolves_with_no_extra_binding(): void
    {
        $engine = $this->engine();

        $dataset = $engine->dataset('traj.aliases')
            ->withSamples([new DatasetSample('s1', ['q' => 'x'], 'y')])
            ->withMetrics([
                'tool-called',
                'tool-not-called',
                'tool-called-with',
                'tool-call-order',
                'steps-below',
                'no-pending-approvals',
                'approval-gated',
            ])
            ->register();

        $this->assertSame([
            'tool-called',
            'tool-not-called',
            'tool-called-with',
            'tool-call-order',
            'steps-below',
            'no-pending-approvals',
            'approval-gated',
        ], $dataset->metricNames());
    }

    /**
     * The end-to-end shape: a runner records what the agent did, and the metric
     * scores it — with no change to the Metric contract every other metric in
     * the package implements.
     */
    public function test_a_runner_records_a_trajectory_and_the_metric_scores_it(): void
    {
        $engine = $this->engine();
        $recorder = $this->recorder();

        $engine->dataset('traj.run')
            ->withSamples([
                new DatasetSample('looks-it-up', ['q' => 'order 7'], 'ships Tuesday', [
                    'trajectory' => ['tools' => ['lookup_order']],
                ]),
                new DatasetSample('guesses', ['q' => 'order 9'], 'ships Friday', [
                    'trajectory' => ['tools' => ['lookup_order']],
                ]),
            ])
            ->withMetrics(['tool-called'])
            ->register();

        $report = $engine->run('traj.run', function (array $input) use ($recorder): string {
            if ($input['q'] === 'order 7') {
                $recorder->record('looks-it-up', new Trajectory([new ToolCall('lookup_order', ['id' => 7])]));

                return 'ships Tuesday';
            }

            // No tool call: the answer is right by luck.
            $recorder->record('guesses', new Trajectory([]));

            return 'ships Friday';
        });

        $this->assertSame(0, $report->totalFailures());

        $scores = [];
        foreach ($report->sampleResults as $result) {
            $scores[$result->sample->id] = $result->metricScores['tool-called']->score;
        }

        $this->assertSame(1.0, $scores['looks-it-up']);
        $this->assertSame(0.0, $scores['guesses'], 'the lucky guess is caught even though the text is correct');
    }

    public function test_the_trajectory_travels_into_the_report(): void
    {
        $engine = $this->engine();
        $recorder = $this->recorder();

        $engine->dataset('traj.report')
            ->withSamples([new DatasetSample('s1', ['q' => 'x'], 'y')])
            ->withMetrics(['exact-match'])
            ->register();

        $report = $engine->run('traj.report', function () use ($recorder): string {
            $recorder->record('s1', new Trajectory(
                toolCalls: [new ToolCall('lookup_order', ['id' => 7])],
                steps: 3,
                finishReason: 'stop',
            ));

            return 'y';
        });

        $json = $report->toJson();

        $this->assertSame(['lookup_order'], array_column($json['samples'][0]['trajectory']['tool_calls'], 'name'));
        $this->assertSame(3, $json['samples'][0]['trajectory']['steps']);
        $this->assertSame('stop', $json['samples'][0]['trajectory']['finish_reason']);
    }

    public function test_a_report_without_trajectories_carries_no_trajectory_key(): void
    {
        $engine = $this->engine();

        $engine->dataset('traj.absent')
            ->withSamples([new DatasetSample('s1', ['q' => 'x'], 'y')])
            ->withMetrics(['exact-match'])
            ->register();

        $json = $engine->run('traj.absent', static fn (): string => 'y')->toJson();

        $this->assertArrayNotHasKey('trajectory', $json['samples'][0]);
    }

    /**
     * Record what an agent did once, and every later run scores it offline,
     * deterministically, for free — no agent runtime involved.
     */
    public function test_saved_outputs_can_carry_trajectories(): void
    {
        $engine = $this->engine();

        $engine->dataset('traj.saved')
            ->withSamples([
                new DatasetSample('s1', ['q' => 'x'], 'y', ['trajectory' => ['tools' => ['lookup_order']]]),
            ])
            ->withMetrics(['tool-called'])
            ->register();

        $report = $engine->scoreOutputs('traj.saved', new SavedOutputs([
            [
                'id' => 's1',
                'actual_output' => 'y',
                'trajectory' => ['tool_calls' => [['name' => 'lookup_order', 'arguments' => ['id' => 7]]], 'steps' => 2],
            ],
        ]));

        $this->assertSame(0, $report->totalFailures());
        $this->assertSame(1.0, $report->sampleResults[0]->metricScores['tool-called']->score);
    }

    /**
     * A metric that cannot see what it is supposed to judge must say so, not
     * quietly score the sample.
     */
    public function test_a_missing_trajectory_is_captured_as_a_failure(): void
    {
        $engine = $this->engine();

        $engine->dataset('traj.missing')
            ->withSamples([new DatasetSample('s1', ['q' => 'x'], 'y', ['trajectory' => ['tools' => ['a']]])])
            ->withMetrics([new ToolCalledMetric])
            ->register();

        $report = $engine->run('traj.missing', static fn (): string => 'y');

        $this->assertSame(1, $report->totalFailures());
        $this->assertSame('tool-called', $report->failures[0]->metricName);
        $this->assertStringContainsString('needs a trajectory', $report->failures[0]->error);
    }

    public function test_repetitions_can_record_a_different_trajectory_each_time(): void
    {
        $engine = $this->engine();
        $recorder = $this->recorder();
        $call = 0;

        $engine->dataset('traj.repetitions')
            ->withSamples([new DatasetSample('s1', ['q' => 'x'], 'y', ['trajectory' => ['tools' => ['lookup_order']]])])
            ->withMetrics(['tool-called'])
            ->register();

        $report = $engine->run('traj.repetitions', function () use ($recorder, &$call): string {
            // Second execution forgets the tool: the row is unstable in a way
            // the text never shows.
            $tools = $call === 1 ? [] : [new ToolCall('lookup_order')];
            $recorder->record('s1', new Trajectory($tools), repetition: $call);
            $call++;

            return 'y';
        }, repetitions: 3);

        $aggregate = $report->sampleAggregates()[0];

        $this->assertSame(3, $aggregate->repetitions);
        $this->assertSame(2, $aggregate->passed);
        $this->assertTrue($aggregate->isUnstable());
    }

    private function engine(): EvalEngine
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        return $engine;
    }

    private function recorder(): TrajectoryRecorder
    {
        /** @var TrajectoryRecorder $recorder */
        $recorder = $this->app->make(TrajectoryRecorder::class);

        return $recorder;
    }
}
