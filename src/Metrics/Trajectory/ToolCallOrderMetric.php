<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics\Trajectory;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Trajectory\Trajectory;

/**
 * Did the agent do things in an order that makes sense?
 *
 * Checking stock *after* charging the card produces the same final message as
 * checking it before, and one of the two is a refund. Order is a property only
 * the trajectory has.
 *
 * Matched as a subsequence: an expectation of `[check_stock, charge_card]` says
 * stock came first, and an agent that also called a currency converter in
 * between has still done that. Requiring an exact sequence would make every new
 * tool a failing eval.
 *
 * Reads `metadata.trajectory.order`.
 */
final class ToolCallOrderMetric extends AbstractTrajectoryMetric
{
    /**
     * @param  list<string>  $order
     */
    public function __construct(private readonly array $order = []) {}

    public function name(): string
    {
        return 'tool-call-order';
    }

    protected function judge(DatasetSample $sample, Trajectory $trajectory): MetricScore
    {
        $expected = $this->expectedList($sample, 'order', $this->order);

        if ($expected === []) {
            return new MetricScore(1.0, [
                'expected' => [],
                'actual' => $trajectory->toolNames(),
                'note' => 'No tool order declared for this sample.',
            ]);
        }

        $followed = $trajectory->followedOrder($expected);

        return new MetricScore($followed ? 1.0 : 0.0, [
            'expected' => $expected,
            'actual' => $trajectory->toolNames(),
            'followed' => $followed,
        ]);
    }
}
