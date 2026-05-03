<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\EvalSets;

use Padosoft\EvalHarness\EvalSets\EvalSetDefinition;
use Padosoft\EvalHarness\EvalSets\EvalSetManifest;
use Padosoft\EvalHarness\EvalSets\EvalSetRunResult;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Reports\EvalReport;
use PHPUnit\Framework\TestCase;

final class EvalSetRunResultTest extends TestCase
{
    public function test_rejects_non_list_reports(): void
    {
        $definition = new EvalSetDefinition('nightly', ['rag.first']);
        $manifest = $this->completedManifest($definition, [$this->report('rag.first')]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('reports must be a zero-based list');

        new EvalSetRunResult(
            definition: $definition,
            manifest: $manifest,
            reports: [1 => $this->report('rag.first')],
        );
    }

    public function test_rejects_non_report_entries(): void
    {
        $definition = new EvalSetDefinition('nightly', ['rag.first']);
        $manifest = $this->completedManifest($definition, [$this->report('rag.first')]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('report at index 0 must be an '.EvalReport::class);

        /** @phpstan-ignore-next-line deliberate malformed report entry */
        new EvalSetRunResult(definition: $definition, manifest: $manifest, reports: [(object) []]);
    }

    public function test_rejects_unknown_dataset_reports(): void
    {
        $definition = new EvalSetDefinition('nightly', ['rag.first']);
        $manifest = $this->completedManifest($definition, [$this->report('rag.first')]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("contains report for unknown dataset 'rag.second'");

        new EvalSetRunResult(
            definition: $definition,
            manifest: $manifest,
            reports: [$this->report('rag.second')],
        );
    }

    public function test_rejects_duplicate_dataset_reports(): void
    {
        $definition = new EvalSetDefinition('nightly', ['rag.first']);
        $manifest = $this->completedManifest($definition, [$this->report('rag.first')]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("contains duplicate report for dataset 'rag.first'");

        new EvalSetRunResult(
            definition: $definition,
            manifest: $manifest,
            reports: [$this->report('rag.first'), $this->report('rag.first')],
        );
    }

    public function test_rejects_report_for_non_completed_manifest_entry(): void
    {
        $definition = new EvalSetDefinition('nightly', ['rag.first']);
        $manifest = EvalSetManifest::start($definition, 1.0);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("contains report for dataset 'rag.first' but the manifest entry is not completed");

        new EvalSetRunResult(
            definition: $definition,
            manifest: $manifest,
            reports: [$this->report('rag.first')],
        );
    }

    public function test_report_for_returns_only_reports_from_current_pass_after_resume(): void
    {
        $definition = new EvalSetDefinition('nightly', ['rag.first', 'rag.second']);
        $firstReport = $this->report('rag.first');
        $secondReport = $this->report('rag.second');
        $manifest = $this->completedManifest($definition, [$firstReport, $secondReport]);

        $result = new EvalSetRunResult(
            definition: $definition,
            manifest: $manifest,
            reports: [$secondReport],
        );

        $this->assertNull($result->reportFor('rag.first'));
        $this->assertSame($secondReport, $result->reportFor('rag.second'));
    }

    /**
     * @param  list<EvalReport>  $reports
     */
    private function completedManifest(EvalSetDefinition $definition, array $reports): EvalSetManifest
    {
        $manifest = EvalSetManifest::start($definition, 1.0);
        foreach ($reports as $report) {
            $manifest = $manifest
                ->markRunning($report->datasetName, $report->startedAt)
                ->markCompleted($report->datasetName, $report);
        }

        return $manifest;
    }

    private function report(string $datasetName): EvalReport
    {
        return new EvalReport(
            datasetName: $datasetName,
            sampleResults: [],
            failures: [],
            startedAt: 1.0,
            finishedAt: 2.0,
        );
    }
}
