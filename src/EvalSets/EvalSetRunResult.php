<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\EvalSets;

use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Reports\EvalReport;

/**
 * Result object for one eval-set pass.
 */
final class EvalSetRunResult
{
    /** @var list<EvalReport> */
    public readonly array $reports;

    /** @var array<string, EvalReport> */
    private array $reportsByDataset = [];

    /**
     * @param  list<EvalReport>  $reports
     */
    public function __construct(
        public readonly EvalSetDefinition $definition,
        public readonly EvalSetManifest $manifest,
        array $reports,
    ) {
        $manifest->assertMatches($definition);

        if (! array_is_list($reports)) {
            throw new EvalRunException(sprintf("Eval set result '%s' reports must be a zero-based list.", $definition->name));
        }

        $definitionDatasetKeys = [];
        foreach ($definition->datasetNames as $datasetName) {
            $definitionDatasetKeys[EvalSetDefinition::datasetNameKey($datasetName)] = true;
        }

        $normalizedReports = [];
        foreach ($reports as $index => $report) {
            if (! $report instanceof EvalReport) {
                throw new EvalRunException(sprintf(
                    "Eval set result '%s' report at index %d must be an %s instance; got %s.",
                    $definition->name,
                    $index,
                    EvalReport::class,
                    get_debug_type($report),
                ));
            }

            $key = EvalSetDefinition::datasetNameKey($report->datasetName);
            if (! isset($definitionDatasetKeys[$key])) {
                throw new EvalRunException(sprintf(
                    "Eval set result '%s' contains report for unknown dataset '%s'.",
                    $definition->name,
                    $report->datasetName,
                ));
            }

            if (isset($this->reportsByDataset[$key])) {
                throw new EvalRunException(sprintf(
                    "Eval set result '%s' contains duplicate report for dataset '%s'.",
                    $definition->name,
                    $report->datasetName,
                ));
            }

            $this->reportsByDataset[$key] = $report;
            $normalizedReports[] = $report;
        }

        $this->reports = $normalizedReports;
    }

    public function reportFor(string $datasetName): ?EvalReport
    {
        return $this->reportsByDataset[EvalSetDefinition::datasetNameKey($datasetName)] ?? null;
    }

    public function isComplete(): bool
    {
        return $this->manifest->isComplete();
    }

    /**
     * @return list<string>
     */
    public function completedDatasetNames(): array
    {
        return $this->manifest->completedDatasetNames();
    }

    /**
     * @return list<string>
     */
    public function failedDatasetNames(): array
    {
        return $this->manifest->failedDatasetNames();
    }
}
