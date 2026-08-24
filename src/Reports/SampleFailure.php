<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Reports;

/**
 * Captured failure for one (sample, metric) pair.
 *
 * The eval engine intentionally captures metric failures rather
 * than aborting the whole run — a single judge timeout shouldn't
 * invalidate 200 valid samples — but each captured failure is
 * surfaced in the final report so the operator can investigate.
 *
 * $repetition is the zero-based execution index the failure happened on,
 * so a row repeated N times can report "the judge timed out on 1 of 5"
 * rather than collapsing an intermittent provider error into a permanent
 * one. It stays 0 for single-execution runs.
 */
final class SampleFailure
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $sampleId,
        public readonly string $metricName,
        public readonly string $error,
        public readonly array $details = [],
        public readonly int $repetition = 0,
    ) {}
}
