<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Datasets;

use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Statistics\SamplingPrecision;

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
 *   - $repetitions: how many times each sample is executed per run.
 *     Defaults to 1, which is the deterministic-pipeline assumption the
 *     package started from. Raise it for anything driven by a model: one
 *     execution of a non-deterministic system is a draw, not a measurement,
 *     and only repetition turns a score into something carrying a confidence
 *     interval (see {@see SamplingPrecision}).
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
        public readonly int $repetitions = 1,
    ) {
        if ($repetitions < 1) {
            throw new DatasetSchemaException(
                sprintf("Dataset '%s' repetitions must be at least 1; got %d.", $name, $repetitions),
            );
        }

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

        foreach ($samples as $index => $sample) {
            if (! $sample instanceof DatasetSample) {
                throw new DatasetSchemaException(sprintf(
                    "Dataset '%s' sample at index %d must be an instance of %s; got %s.",
                    $name,
                    $index,
                    DatasetSample::class,
                    get_debug_type($sample),
                ));
            }
        }
    }

    public function sampleCount(): int
    {
        return count($this->samples);
    }

    /**
     * Executions a full run of this dataset performs.
     *
     * Rows times repetitions — the number that decides how long the run takes
     * and what it costs, as opposed to {@see sampleCount()}, which is the size
     * of the curated dataset.
     */
    public function executionCount(): int
    {
        return $this->sampleCount() * $this->repetitions;
    }

    /**
     * @return list<string>
     */
    public function metricNames(): array
    {
        return array_map(static fn (Metric $m): string => $m->name(), $this->metrics);
    }
}
