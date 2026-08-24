<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics\Trajectory;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Trajectory\Trajectory;

/**
 * Did the agent get there without wandering?
 *
 * An agent that needs eleven steps for a two-step job still produces the right
 * answer, and costs eleven times as much to run. Step count is where an agent
 * regression shows up first — before the answers get worse, they get expensive
 * — and it is invisible in the text.
 *
 * Reads `metadata.trajectory.max_steps`, or a limit given to the constructor.
 * With no limit declared anywhere the metric scores 1.0 and says so, rather
 * than inventing a threshold nobody chose.
 */
final class StepsBelowMetric extends AbstractTrajectoryMetric
{
    public function __construct(private readonly ?int $maxSteps = null) {}

    public function name(): string
    {
        return 'steps-below';
    }

    protected function judge(DatasetSample $sample, Trajectory $trajectory): MetricScore
    {
        $limit = $this->expectedInt($sample, 'max_steps', $this->maxSteps);
        $steps = $trajectory->stepCount();

        if ($limit === null || $limit < 1) {
            return new MetricScore(1.0, [
                'steps' => $steps,
                'max_steps' => null,
                'note' => 'No step budget declared for this sample.',
            ]);
        }

        return new MetricScore($steps <= $limit ? 1.0 : 0.0, [
            'steps' => $steps,
            'max_steps' => $limit,
            'over_by' => max(0, $steps - $limit),
        ]);
    }
}
