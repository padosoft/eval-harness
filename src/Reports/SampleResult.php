<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Reports;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\MetricScore;

/**
 * Per-sample result captured during an eval run.
 *
 * - $metricScores: keyed by metric name. A metric that threw is
 *   absent from the map; the failure is captured in {@see EvalReport::failures}.
 * - $actualOutput is recorded so the JSON report can reproduce
 *   the LLM judge's view; useful for diagnosing low scores.
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
    ) {}
}
