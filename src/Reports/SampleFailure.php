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
    ) {}
}
