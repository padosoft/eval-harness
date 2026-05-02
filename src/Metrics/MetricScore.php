<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Single-sample metric output.
 *
 * - $score is constrained to [0.0, 1.0]. The constructor enforces
 *   the range; a metric implementation that violates it gets a
 *   {@see MetricException} immediately, not a silently-wrong report.
 * - $details is a per-sample diagnostic bag (judge raw response,
 *   embedding cosine, etc.). Surfaced verbatim in the JSON report
 *   for debugging.
 */
final class MetricScore
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly float $score,
        public readonly array $details = [],
    ) {
        if ($score < 0.0 || $score > 1.0 || is_nan($score)) {
            throw new MetricException(
                sprintf('Metric score must be in [0.0, 1.0]; got %s.', var_export($score, true)),
            );
        }
    }
}
