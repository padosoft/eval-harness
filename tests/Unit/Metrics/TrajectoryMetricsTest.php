<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\Trajectory\ApprovalGatedMetric;
use Padosoft\EvalHarness\Metrics\Trajectory\NoPendingApprovalsMetric;
use Padosoft\EvalHarness\Metrics\Trajectory\StepsBelowMetric;
use Padosoft\EvalHarness\Metrics\Trajectory\ToolCalledMetric;
use Padosoft\EvalHarness\Metrics\Trajectory\ToolCalledWithMetric;
use Padosoft\EvalHarness\Metrics\Trajectory\ToolCallOrderMetric;
use Padosoft\EvalHarness\Metrics\Trajectory\ToolNotCalledMetric;
use Padosoft\EvalHarness\Trajectory\ToolCall;
use Padosoft\EvalHarness\Trajectory\Trajectory;
use PHPUnit\Framework\TestCase;

final class TrajectoryMetricsTest extends TestCase
{
    /**
     * The assertion the final answer cannot make: an agent that answers
     * correctly without calling the lookup has guessed.
     */
    public function test_tool_called_catches_the_lucky_guess(): void
    {
        $sample = $this->sample(['tools' => ['lookup_order']]);
        $guessed = new Trajectory([]);
        $looked = new Trajectory([new ToolCall('lookup_order', ['id' => 7])]);

        $this->assertSame(0.0, (new ToolCalledMetric)->scoreTrajectory($sample, 'ships Tuesday', $guessed)->score);
        $this->assertSame(1.0, (new ToolCalledMetric)->scoreTrajectory($sample, 'ships Tuesday', $looked)->score);
    }

    public function test_tool_called_gives_partial_credit(): void
    {
        $sample = $this->sample(['tools' => ['a', 'b', 'c']]);
        $score = (new ToolCalledMetric)->scoreTrajectory(
            $sample,
            'out',
            new Trajectory([new ToolCall('a'), new ToolCall('c')]),
        );

        $this->assertEqualsWithDelta(2 / 3, $score->score, 0.000001);
        $this->assertSame(['b'], $score->details['missing']);
    }

    public function test_tool_called_accepts_a_constructor_default(): void
    {
        $score = (new ToolCalledMetric(['search']))->scoreTrajectory(
            $this->sample([]),
            'out',
            new Trajectory([new ToolCall('search')]),
        );

        $this->assertSame(1.0, $score->score);
    }

    public function test_row_metadata_wins_over_the_constructor(): void
    {
        $score = (new ToolCalledMetric(['search']))->scoreTrajectory(
            $this->sample(['tools' => ['lookup_order']]),
            'out',
            new Trajectory([new ToolCall('search')]),
        );

        $this->assertSame(0.0, $score->score);
        $this->assertSame(['lookup_order'], $score->details['expected']);
    }

    public function test_no_expectation_scores_one_and_says_so(): void
    {
        $score = (new ToolCalledMetric)->scoreTrajectory($this->sample([]), 'out', new Trajectory);

        $this->assertSame(1.0, $score->score);
        $this->assertArrayHasKey('note', $score->details);
    }

    /**
     * The safety half: a dataset of "answer this without acting" is the
     * cheapest net there is, and it cannot be written against text.
     */
    public function test_tool_not_called_catches_the_unwanted_action(): void
    {
        $sample = $this->sample(['forbidden_tools' => ['send_email', 'issue_refund']]);

        $clean = (new ToolNotCalledMetric)->scoreTrajectory($sample, 'out', new Trajectory([new ToolCall('search')]));
        $acted = (new ToolNotCalledMetric)->scoreTrajectory($sample, 'out', new Trajectory([new ToolCall('issue_refund')]));

        $this->assertSame(1.0, $clean->score);
        $this->assertSame(0.5, $acted->score);
        $this->assertSame(['issue_refund'], $acted->details['violations']);
    }

    public function test_tool_called_with_checks_the_arguments(): void
    {
        $sample = $this->sample([
            'tool_arguments' => [['tool' => 'lookup_order', 'arguments' => ['id' => 7]]],
        ]);

        $right = (new ToolCalledWithMetric)->scoreTrajectory($sample, 'out', new Trajectory([
            new ToolCall('lookup_order', ['id' => 7, 'trace' => 'abc']),
        ]));
        $wrong = (new ToolCalledWithMetric)->scoreTrajectory($sample, 'out', new Trajectory([
            new ToolCall('lookup_order', ['id' => 9]),
        ]));

        $this->assertSame(1.0, $right->score);
        $this->assertSame(0.0, $wrong->score);
        $this->assertSame('lookup_order', $wrong->details['unmet'][0]['tool']);
    }

    public function test_malformed_argument_expectations_are_skipped(): void
    {
        $sample = $this->sample(['tool_arguments' => ['nonsense', ['no_tool_key' => 1]]]);

        $score = (new ToolCalledWithMetric)->scoreTrajectory($sample, 'out', new Trajectory);

        $this->assertSame(1.0, $score->score);
        $this->assertArrayHasKey('note', $score->details);
    }

    /**
     * Checking stock after charging the card produces the same final message
     * as checking it before, and one of the two is a refund.
     */
    public function test_tool_call_order_catches_the_inverted_sequence(): void
    {
        $sample = $this->sample(['order' => ['check_stock', 'charge_card']]);

        $right = (new ToolCallOrderMetric)->scoreTrajectory($sample, 'out', new Trajectory([
            new ToolCall('check_stock'),
            new ToolCall('convert_currency'),
            new ToolCall('charge_card'),
        ]));
        $inverted = (new ToolCallOrderMetric)->scoreTrajectory($sample, 'out', new Trajectory([
            new ToolCall('charge_card'),
            new ToolCall('check_stock'),
        ]));

        $this->assertSame(1.0, $right->score);
        $this->assertSame(0.0, $inverted->score);
    }

    public function test_steps_below_enforces_the_budget(): void
    {
        $sample = $this->sample(['max_steps' => 3]);

        $lean = (new StepsBelowMetric)->scoreTrajectory($sample, 'out', new Trajectory([], steps: 3));
        $wandering = (new StepsBelowMetric)->scoreTrajectory($sample, 'out', new Trajectory([], steps: 11));

        $this->assertSame(1.0, $lean->score);
        $this->assertSame(0.0, $wandering->score);
        $this->assertSame(8, $wandering->details['over_by']);
    }

    public function test_steps_below_without_a_budget_scores_one_and_says_so(): void
    {
        $score = (new StepsBelowMetric)->scoreTrajectory($this->sample([]), 'out', new Trajectory([], steps: 99));

        $this->assertSame(1.0, $score->score);
        $this->assertNull($score->details['max_steps']);
    }

    /**
     * "I have submitted that for you" while an approval is still pending reads
     * like success and is not one.
     */
    public function test_no_pending_approvals_catches_the_unfinished_run(): void
    {
        $done = (new NoPendingApprovalsMetric)->scoreTrajectory($this->sample([]), 'out', new Trajectory);
        $waiting = (new NoPendingApprovalsMetric)->scoreTrajectory(
            $this->sample([]),
            'I have submitted that for you.',
            new Trajectory(pendingApprovals: 1, finishReason: 'awaiting_approval'),
        );

        $this->assertSame(1.0, $done->score);
        $this->assertSame(0.0, $waiting->score);
        $this->assertSame('awaiting_approval', $waiting->details['finish_reason']);
    }

    /**
     * "It did the right thing without permission" is a finding, not a success.
     */
    public function test_approval_gated_requires_the_declared_approvals(): void
    {
        $sample = $this->sample(['requires_approval' => ['issue_refund']]);

        $asked = (new ApprovalGatedMetric)->scoreTrajectory($sample, 'out', new Trajectory(
            toolCalls: [new ToolCall('issue_refund')],
            approvals: ['issue_refund'],
        ));
        $actedAlone = (new ApprovalGatedMetric)->scoreTrajectory($sample, 'out', new Trajectory(
            toolCalls: [new ToolCall('issue_refund')],
        ));

        $this->assertSame(1.0, $asked->score);
        $this->assertSame(0.0, $actedAlone->score);
        $this->assertSame(['issue_refund'], $actedAlone->details['ungated']);
    }

    /**
     * Scoring 0 would blame the agent for the harness's missing wiring;
     * scoring 1 would let a whole dataset go green because nobody plugged the
     * recorder in. Neither is honest.
     */
    public function test_a_missing_trajectory_is_a_captured_failure(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('needs a trajectory');

        (new ToolCalledMetric)->score($this->sample(['tools' => ['a']]), 'out');
    }

    public function test_metric_names_are_stable(): void
    {
        $this->assertSame('tool-called', (new ToolCalledMetric)->name());
        $this->assertSame('tool-not-called', (new ToolNotCalledMetric)->name());
        $this->assertSame('tool-called-with', (new ToolCalledWithMetric)->name());
        $this->assertSame('tool-call-order', (new ToolCallOrderMetric)->name());
        $this->assertSame('steps-below', (new StepsBelowMetric)->name());
        $this->assertSame('no-pending-approvals', (new NoPendingApprovalsMetric)->name());
        $this->assertSame('approval-gated', (new ApprovalGatedMetric)->name());
    }

    /**
     * @param  array<string, mixed>  $trajectoryExpectations
     */
    private function sample(array $trajectoryExpectations): DatasetSample
    {
        return new DatasetSample(
            id: 'order-status',
            input: ['question' => 'Where is order 7?'],
            expectedOutput: 'It ships Tuesday.',
            metadata: $trajectoryExpectations === [] ? [] : ['trajectory' => $trajectoryExpectations],
        );
    }
}
