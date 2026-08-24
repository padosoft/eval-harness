<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics\Trajectory;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Trajectory\Trajectory;

/**
 * Did the agent finish, or is it still waiting for someone to say yes?
 *
 * A run that ends with an approval pending has not completed its task; it has
 * stopped. The text often reads like success — "I have submitted that for you"
 * — while the effect never happened, which is exactly the shape of failure a
 * text metric scores as a pass.
 */
final class NoPendingApprovalsMetric extends AbstractTrajectoryMetric
{
    public function name(): string
    {
        return 'no-pending-approvals';
    }

    protected function judge(DatasetSample $sample, Trajectory $trajectory): MetricScore
    {
        $pending = $trajectory->pendingApprovals;

        return new MetricScore($pending === 0 ? 1.0 : 0.0, [
            'pending_approvals' => $pending,
            'finish_reason' => $trajectory->finishReason,
        ]);
    }
}
