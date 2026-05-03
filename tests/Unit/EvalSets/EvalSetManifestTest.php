<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\EvalSets;

use Padosoft\EvalHarness\EvalSets\EvalSetDefinition;
use Padosoft\EvalHarness\EvalSets\EvalSetManifest;
use Padosoft\EvalHarness\EvalSets\EvalSetManifestEntry;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\ReportSchema;
use PHPUnit\Framework\TestCase;

final class EvalSetManifestTest extends TestCase
{
    public function test_manifest_tracks_progress_and_round_trips_json(): void
    {
        $definition = new EvalSetDefinition('nightly', ['rag.first', 'rag.second']);
        $report = new EvalReport(
            datasetName: 'rag.first',
            sampleResults: [],
            failures: [],
            startedAt: 10.0,
            finishedAt: 12.5,
        );

        $manifest = EvalSetManifest::start($definition, 10.0)
            ->markRunning('rag.first', 10.5)
            ->markCompleted('rag.first', $report)
            ->markRunning('rag.second', 13.0)
            ->markFailed('rag.second', 'boom', 14.0);

        $this->assertSame(['rag.first'], $manifest->completedDatasetNames());
        $this->assertSame(['rag.second'], $manifest->failedDatasetNames());
        $this->assertFalse($manifest->isComplete());

        $json = $manifest->toJson();
        $this->assertSame(EvalSetManifest::SCHEMA_VERSION, $json['schema_version']);
        $this->assertSame('nightly', $json['eval_set']);
        $this->assertSame(12.5, $json['datasets'][0]['finished_at']);
        $this->assertSame(2.0, $json['datasets'][0]['duration_seconds']);
        $this->assertSame(ReportSchema::VERSION, $json['datasets'][0]['report_schema_version']);
        $this->assertSame('boom', $json['datasets'][1]['error']);

        $roundTrip = EvalSetManifest::fromJson($json);
        $this->assertSame($json, $roundTrip->toJson());
    }

    public function test_manifest_asserts_eval_set_name_and_dataset_order(): void
    {
        $manifest = EvalSetManifest::start(new EvalSetDefinition('nightly', ['rag.first', 'rag.second']), 1.0);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('dataset order');

        $manifest->assertMatches(new EvalSetDefinition('nightly', ['rag.second', 'rag.first']));
    }

    public function test_manifest_preserves_numeric_like_dataset_names_without_key_coercion(): void
    {
        $manifest = new EvalSetManifest(
            evalSetName: 'nightly',
            entries: [
                EvalSetManifestEntry::pending('1'),
                EvalSetManifestEntry::pending('01'),
            ],
            startedAt: 1.0,
            updatedAt: 1.0,
        );

        $this->assertSame(['1', '01'], array_map(
            static fn (EvalSetManifestEntry $entry): string => $entry->datasetName,
            $manifest->entries,
        ));
        $this->assertSame('01', $manifest->entryFor('01')?->datasetName);
        $this->assertNull($manifest->entryFor('1 '));
    }

    public function test_manifest_rejects_completion_report_for_another_dataset(): void
    {
        $manifest = EvalSetManifest::start(new EvalSetDefinition('nightly', ['rag.first']), 1.0);
        $report = new EvalReport(
            datasetName: 'rag.second',
            sampleResults: [],
            failures: [],
            startedAt: 1.0,
            finishedAt: 2.0,
        );

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("cannot be completed with report for dataset 'rag.second'");

        $manifest->markCompleted('rag.first', $report);
    }

    public function test_manifest_rejects_inconsistent_terminal_status_fields(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('completed status requires report summary fields');

        new EvalSetManifestEntry(datasetName: 'rag.first', status: EvalSetManifestEntry::STATUS_COMPLETED);
    }

    public function test_manifest_rejects_completed_dataset_after_pending_dataset(): void
    {
        $report = new EvalReport(
            datasetName: 'rag.second',
            sampleResults: [],
            failures: [],
            startedAt: 2.0,
            finishedAt: 3.0,
        );
        $completedSecond = EvalSetManifestEntry::pending('rag.second')
            ->running(2.0)
            ->completed($report);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("cannot mark dataset 'rag.second' as 'completed' after dataset 'rag.first' is 'pending'");

        new EvalSetManifest(
            evalSetName: 'nightly',
            entries: [
                EvalSetManifestEntry::pending('rag.first'),
                $completedSecond,
            ],
            startedAt: 1.0,
            updatedAt: 3.0,
        );
    }

    public function test_manifest_rejects_finished_at_before_started_at(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('finished_at cannot be earlier than started_at');

        new EvalSetManifestEntry(
            datasetName: 'rag.first',
            status: EvalSetManifestEntry::STATUS_FAILED,
            startedAt: 5.0,
            finishedAt: 4.0,
            durationSeconds: 0.0,
            error: 'boom',
        );
    }

    public function test_manifest_rejects_duration_that_does_not_match_timestamps(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('duration_seconds must match finished_at minus started_at');

        new EvalSetManifestEntry(
            datasetName: 'rag.first',
            status: EvalSetManifestEntry::STATUS_FAILED,
            startedAt: 4.0,
            finishedAt: 6.0,
            durationSeconds: 3.0,
            error: 'boom',
        );
    }

    public function test_manifest_rejects_terminal_status_transitions(): void
    {
        $report = new EvalReport(
            datasetName: 'rag.first',
            sampleResults: [],
            failures: [],
            startedAt: 1.0,
            finishedAt: 2.0,
        );
        $entry = EvalSetManifestEntry::pending('rag.first')
            ->running(1.0)
            ->completed($report);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('terminal status');

        $entry->running(3.0);
    }

    public function test_manifest_resets_started_at_when_resuming_running_entry(): void
    {
        $entry = EvalSetManifestEntry::pending('rag.first')
            ->running(1.0)
            ->running(5.0);

        $this->assertSame(5.0, $entry->startedAt);
    }

    public function test_manifest_rejects_padded_dataset_names_instead_of_trimming(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('without leading or trailing whitespace');

        EvalSetManifestEntry::pending(' rag.first ');
    }
}
