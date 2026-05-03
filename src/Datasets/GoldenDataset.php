<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Datasets;

use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
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
        public readonly string $schemaVersion = DatasetSchema::VERSION,
    ) {
        if (! DatasetSchema::isSupported($schemaVersion)) {
            throw new DatasetSchemaException(
                sprintf(
                    "Dataset '%s' uses unsupported schema version '%s'. Supported versions: %s.",
                    $name,
                    $schemaVersion,
                    implode(', ', DatasetSchema::SUPPORTED_VERSIONS),
                ),
            );
        }

        if (! array_is_list($samples)) {
            throw new DatasetSchemaException(
                sprintf("Dataset '%s' samples must be a zero-based list.", $name),
            );
        }
    }

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
