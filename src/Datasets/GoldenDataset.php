<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Datasets;

use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Reports\EvalReport;

/**
 * Immutable, registered golden dataset.
 *
 * Shape:
 *   - $name: dotted identifier ("rag.factuality.fy2026"). Globally
 *     unique within an EvalEngine instance.
 *   - $samples: list of {@see DatasetSample}, order-preserving.
 *   - $metrics: list of resolved Metric instances. The harness scores
 *     every sample against every metric; aggregation lives in
 *     {@see EvalReport}.
 */
final class GoldenDataset
{
    /**
     * @param  list<DatasetSample>  $samples
     * @param  list<Metric>  $metrics
     */
    public function __construct(
        public readonly string $name,
        public readonly array $samples,
        public readonly array $metrics,
    ) {}

    public function sampleCount(): int
    {
        return count($this->samples);
    }

    /**
     * @return list<string>
     */
    public function metricNames(): array
    {
        return array_map(static fn (Metric $m): string => $m->name(), $this->metrics);
    }
}
