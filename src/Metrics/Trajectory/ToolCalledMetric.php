<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics\Trajectory;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Trajectory\Trajectory;

/**
 * Did the agent call the tools this row needed?
 *
 * The assertion the final answer cannot make. An agent that answers "your order
 * ships Tuesday" without ever calling the order lookup has guessed, and every
 * text metric in this package will happily score that guess 1.0 when the guess
 * happens to be right — which it will be, for the common case, in a way that
 * quietly trains everyone to trust it.
 *
 * Reads `metadata.trajectory.tools`, or a list given to the constructor when a
 * whole dataset shares one rule. Partial credit: two of three expected tools is
 * a different state from none of them.
 */
final class ToolCalledMetric extends AbstractTrajectoryMetric
{
    /**
     * @param  list<string>  $tools
     */
    public function __construct(private readonly array $tools = []) {}

    public function name(): string
    {
        return 'tool-called';
    }

    protected function judge(DatasetSample $sample, Trajectory $trajectory): MetricScore
    {
        $expected = $this->expectedList($sample, 'tools', $this->tools);

        if ($expected === []) {
            return new MetricScore(1.0, [
                'expected' => [],
                'called' => $trajectory->toolNames(),
                'note' => 'No tools expected for this sample.',
            ]);
        }

        $missing = [];
        $satisfied = [];

        foreach ($expected as $tool) {
            $called = $trajectory->called($tool);
            $satisfied[] = $called;

            if (! $called) {
                $missing[] = $tool;
            }
        }

        return new MetricScore($this->fraction($satisfied), [
            'expected' => $expected,
            'called' => $trajectory->toolNames(),
            'missing' => $missing,
        ]);
    }
}
