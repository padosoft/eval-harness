<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Trajectory;

use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Trajectory\ToolCall;
use Padosoft\EvalHarness\Trajectory\Trajectory;
use Padosoft\EvalHarness\Trajectory\TrajectoryRecorder;
use PHPUnit\Framework\TestCase;

final class TrajectoryTest extends TestCase
{
    public function test_tool_names_preserve_call_order(): void
    {
        $trajectory = new Trajectory([
            new ToolCall('search'),
            new ToolCall('lookup_order'),
            new ToolCall('answer'),
        ]);

        $this->assertSame(['search', 'lookup_order', 'answer'], $trajectory->toolNames());
        $this->assertTrue($trajectory->called('lookup_order'));
        $this->assertFalse($trajectory->called('send_email'));
        $this->assertSame(1, $trajectory->callCount('search'));
    }

    public function test_step_count_falls_back_to_the_number_of_calls(): void
    {
        $withSteps = new Trajectory([new ToolCall('a')], steps: 5);
        $withoutSteps = new Trajectory([new ToolCall('a'), new ToolCall('b')]);

        $this->assertSame(5, $withSteps->stepCount());
        $this->assertSame(2, $withoutSteps->stepCount());
    }

    /**
     * Subset, not equality: an assertion says "it looked up order 7", and a
     * runtime that also passes a trace id has still done that.
     */
    public function test_arguments_match_as_a_subset(): void
    {
        $trajectory = new Trajectory([
            new ToolCall('lookup_order', ['id' => 7, 'trace' => 'abc', 'locale' => 'it']),
        ]);

        $this->assertTrue($trajectory->calledWith('lookup_order', ['id' => 7]));
        $this->assertTrue($trajectory->calledWith('lookup_order', ['id' => 7, 'locale' => 'it']));
        $this->assertFalse($trajectory->calledWith('lookup_order', ['id' => 8]));
        $this->assertFalse($trajectory->calledWith('lookup_order', ['missing' => 'key']));
        $this->assertFalse($trajectory->calledWith('other_tool', ['id' => 7]));
    }

    /**
     * A JSON round-trip turns 7 into "7" often enough that strict comparison
     * would fail on transport rather than on behaviour.
     */
    public function test_numeric_arguments_compare_across_string_and_int(): void
    {
        $trajectory = new Trajectory([new ToolCall('lookup_order', ['id' => '7'])]);

        $this->assertTrue($trajectory->calledWith('lookup_order', ['id' => 7]));
    }

    public function test_nested_arguments_match_recursively(): void
    {
        $trajectory = new Trajectory([
            new ToolCall('charge', ['payment' => ['amount' => 10, 'currency' => 'EUR', 'ref' => 'x']]),
        ]);

        $this->assertTrue($trajectory->calledWith('charge', ['payment' => ['currency' => 'EUR']]));
        $this->assertFalse($trajectory->calledWith('charge', ['payment' => ['currency' => 'USD']]));
    }

    /**
     * Subsequence, not equality: requiring an exact sequence would turn every
     * newly added tool into a failing eval.
     */
    public function test_order_is_matched_as_a_subsequence(): void
    {
        $trajectory = new Trajectory([
            new ToolCall('check_stock'),
            new ToolCall('convert_currency'),
            new ToolCall('charge_card'),
        ]);

        $this->assertTrue($trajectory->followedOrder(['check_stock', 'charge_card']));
        $this->assertFalse($trajectory->followedOrder(['charge_card', 'check_stock']));
        $this->assertTrue($trajectory->followedOrder([]));
        $this->assertFalse($trajectory->followedOrder(['check_stock', 'refund']));
    }

    public function test_failed_calls_are_listed(): void
    {
        $trajectory = new Trajectory([
            new ToolCall('ok_tool', result: 'fine'),
            new ToolCall('broken_tool', error: 'timeout'),
        ]);

        $this->assertCount(1, $trajectory->failedCalls());
        $this->assertSame('broken_tool', $trajectory->failedCalls()[0]->name);
        $this->assertTrue($trajectory->failedCalls()[0]->failed());
    }

    public function test_a_bare_list_of_names_is_accepted(): void
    {
        $trajectory = Trajectory::fromArray(['tool_calls' => ['search', 'answer']]);

        $this->assertSame(['search', 'answer'], $trajectory->toolNames());
    }

    public function test_from_array_reads_the_full_shape(): void
    {
        $trajectory = Trajectory::fromArray([
            'tool_calls' => [
                ['name' => 'lookup_order', 'arguments' => ['id' => 7], 'duration_ms' => 12],
                ['tool' => 'issue_refund', 'args' => ['amount' => 10], 'error' => 'declined'],
            ],
            'steps' => 4,
            'finish_reason' => 'stop',
            'pending_approvals' => 1,
            'approvals' => ['issue_refund', 42],
            'metadata' => ['model' => 'x'],
        ]);

        $this->assertSame(['lookup_order', 'issue_refund'], $trajectory->toolNames());
        $this->assertSame(4, $trajectory->stepCount());
        $this->assertSame('stop', $trajectory->finishReason);
        $this->assertSame(1, $trajectory->pendingApprovals);
        $this->assertSame(['issue_refund'], $trajectory->approvals, 'non-string approvals are dropped');
        $this->assertTrue($trajectory->hasApproval('issue_refund'));
        $this->assertSame(['model' => 'x'], $trajectory->metadata);
        $this->assertSame(12, $trajectory->toolCalls[0]->durationMs);
    }

    public function test_round_trips_through_array(): void
    {
        $original = Trajectory::fromArray([
            'tool_calls' => [['name' => 'a', 'arguments' => ['x' => 1]]],
            'steps' => 2,
            'finish_reason' => 'stop',
            'approvals' => ['act'],
        ]);

        $restored = Trajectory::fromArray($original->toArray());

        $this->assertEquals($original->toolNames(), $restored->toolNames());
        $this->assertSame($original->stepCount(), $restored->stepCount());
        $this->assertSame($original->finishReason, $restored->finishReason);
        $this->assertSame($original->approvals, $restored->approvals);
    }

    public function test_a_nameless_tool_call_is_rejected(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('non-empty name');

        new ToolCall('');
    }

    public function test_a_tool_call_entry_without_a_name_is_rejected(): void
    {
        $this->expectException(EvalRunException::class);

        ToolCall::fromArray(['arguments' => ['id' => 1]]);
    }

    public function test_empty_trajectory_is_detectable(): void
    {
        $this->assertTrue((new Trajectory)->isEmpty());
        $this->assertFalse((new Trajectory([new ToolCall('a')]))->isEmpty());
    }

    public function test_recorder_keeps_executions_apart_and_falls_back_to_the_row(): void
    {
        $recorder = new TrajectoryRecorder;

        $recorder->record('s1', new Trajectory([new ToolCall('row_level')]));
        $recorder->record('s1', new Trajectory([new ToolCall('second_try')]), repetition: 1);

        $this->assertSame(['row_level'], $recorder->for('s1')->toolNames());
        $this->assertSame(['second_try'], $recorder->for('s1', 1)->toolNames());
        $this->assertSame(
            ['row_level'],
            $recorder->for('s1', 0)->toolNames(),
            'an execution with no trajectory of its own falls back to the row',
        );
        $this->assertNull($recorder->for('unknown'));
        $this->assertTrue($recorder->has('s1'));
        $this->assertFalse($recorder->has('unknown'));
    }

    public function test_recorder_flushes_between_runs(): void
    {
        $recorder = new TrajectoryRecorder;
        $recorder->record('s1', new Trajectory([new ToolCall('a')]));

        $this->assertSame(1, $recorder->count());

        $recorder->flush();

        $this->assertSame(0, $recorder->count());
        $this->assertNull($recorder->for('s1'));
    }
}
