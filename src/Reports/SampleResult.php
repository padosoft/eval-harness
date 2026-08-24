<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Reports;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Trajectory\Trajectory;

/**
 * Per-sample result captured during an eval run.
 *
 * - $metricScores: keyed by metric name. A metric that threw is
 *   absent from the map; the failure is captured in {@see EvalReport::failures}.
 * - $actualOutput is recorded so the JSON report can reproduce
 *   the LLM judge's view; useful for diagnosing low scores.
 * - $repetition is the zero-based execution index when a run repeats
 *   each row (`--repetitions=N`). It stays 0 for a single-execution run,
 *   which is why every existing caller and every existing report keeps
 *   working unchanged. Aggregation across repetitions lives in
 *   {@see SampleAggregate}.
 * - $trajectory is how the answer was produced — tool calls, steps,
 *   approvals — when a system under test recorded one. Null for a
 *   pipeline that only produces text, which is most of them.
 */
final class SampleResult
{
    /**
     * @param  array<string, MetricScore>  $metricScores
     */
    public function __construct(
        public readonly DatasetSample $sample,
        public readonly string $actualOutput,
        public readonly array $metricScores,
        public readonly int $repetition = 0,
        public readonly ?Trajectory $trajectory = null,
    ) {}
}
