<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics\Trajectory;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Trajectory\Trajectory;

/**
 * A metric that scores *how* an answer was produced, not just the answer.
 *
 * The engine hands these the trajectory recorded for the execution being
 * scored; every other metric is called exactly as before. Extending the base
 * `Metric` contract for all implementations would have been the alternative,
 * and would have changed every metric in the package and every one a host has
 * written, to serve one family.
 */
interface TrajectoryMetric extends Metric
{
    public function scoreTrajectory(DatasetSample $sample, string $actualOutput, ?Trajectory $trajectory): MetricScore;
}
