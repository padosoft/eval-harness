<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Adversarial;

use Padosoft\EvalHarness\Adversarial\AdversarialRegressionGate;
use Padosoft\EvalHarness\Adversarial\AdversarialRegressionGateResult;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifest;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifestEntry;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifestStore;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\SampleFailure;
use Padosoft\EvalHarness\Reports\SampleResult;
use PHPUnit\Framework\TestCase;

final class AdversarialRunManifestTest extends TestCase
{
    public function test_entry_from_report_captures_safe_summary_and_round_trips_json(): void
    {
        $entry = AdversarialRunManifestEntry::fromReport($this->report('run.dataset', 10.0, 12.5, 1.0), 'run-1');

        $this->assertSame('run-1', $entry->runId);
        $this->assertSame('run.dataset', $entry->datasetName);
        $this->assertSame(2.5, $entry->durationSeconds);
        $this->assertSame(1, $entry->totalSamples);
        $this->assertSame(0, $entry->totalFailures);
        $this->assertSame(1.0, $entry->macroF1);
        $this->assertSame(1.0, $entry->metrics['exact-match']['mean']);
        $this->assertSame(1, $entry->adversarial['total_samples']);
        $this->assertSame('prompt-injection', $entry->adversarial['categories'][0]['category']);

        $roundTrip = AdversarialRunManifestEntry::fromJson($entry->toJson());
        $this->assertSame($entry->toJson(), $roundTrip->toJson());
    }

    public function test_entry_default_run_id_uses_locale_independent_float_format(): void
    {
        $report = $this->report('run.dataset', 1.1234567, 2.7654321, 1.0);
        $entry = AdversarialRunManifestEntry::fromReport($report);

        $this->assertSame(hash('sha256', implode('|', [
            'run.dataset',
            '1.123457',
            '2.765432',
            '1',
            '0',
        ])), $entry->runId);
    }

    public function test_entry_allows_metric_failure_count_above_sample_count(): void
    {
        $report = new EvalReport(
            datasetName: 'run.dataset',
            sampleResults: [
                new SampleResult(
                    sample: $this->sample(),
                    actualOutput: 'unsafe',
                    metricScores: ['exact-match' => new MetricScore(0.0)],
                ),
            ],
            failures: [
                new SampleFailure('adv.prompt-injection', 'llm-as-judge', 'judge failed'),
                new SampleFailure('adv.prompt-injection', 'refusal-quality', 'judge failed'),
            ],
            startedAt: 1.0,
            finishedAt: 2.0,
        );

        $entry = AdversarialRunManifestEntry::fromReport($report, 'run-failures');

        $this->assertSame(1, $entry->totalSamples);
        $this->assertSame(2, $entry->totalFailures);
        $this->assertSame(0.0, $entry->metrics['llm-as-judge']['mean']);
        $this->assertSame(0.0, $entry->metrics['refusal-quality']['pass_rate']);
    }

    public function test_entry_rejects_invalid_nested_adversarial_metric_aggregates(): void
    {
        $payload = AdversarialRunManifestEntry::fromReport($this->report('run.dataset', 1.0, 2.0), 'run-1')->toJson();
        $payload['adversarial']['categories'][0]['metrics']['exact-match']['pass_rate'] = 1.5;

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Adversarial run manifest metric 'exact-match' aggregate 'pass_rate' must be in [0, 1].");

        AdversarialRunManifestEntry::fromJson($payload);
    }

    public function test_entry_rejects_macro_f1_above_one(): void
    {
        $payload = AdversarialRunManifestEntry::fromReport($this->report('run.dataset', 1.0, 2.0), 'run-1')->toJson();
        $payload['macro_f1'] = 1.1;

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Adversarial run manifest entry field 'macro_f1' must be in [0, 1].");

        AdversarialRunManifestEntry::fromJson($payload);
    }

    public function test_entry_rejects_malformed_adversarial_category_shape(): void
    {
        $payload = AdversarialRunManifestEntry::fromReport($this->report('run.dataset', 1.0, 2.0), 'run-1')->toJson();
        $payload['adversarial']['categories'][0]['sample_count'] = '1';

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Adversarial run manifest adversarial.categories[0].sample_count must be a non-negative integer.');

        AdversarialRunManifestEntry::fromJson($payload);
    }

    public function test_entry_rejects_malformed_adversarial_framework_shape(): void
    {
        $payload = AdversarialRunManifestEntry::fromReport($this->report('run.dataset', 1.0, 2.0), 'run-1')->toJson();
        $payload['adversarial']['compliance_frameworks'][0]['categories'] = ['prompt-injection', ''];

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Adversarial run manifest adversarial.compliance_frameworks[0].categories[1] must be a non-empty string without leading or trailing whitespace.');

        AdversarialRunManifestEntry::fromJson($payload);
    }

    public function test_manifest_records_newest_runs_and_retains_last_n(): void
    {
        $manifest = AdversarialRunManifest::empty('adversarial.security', 1.0)
            ->record(AdversarialRunManifestEntry::fromReport($this->report('run.dataset', 1.0, 10.0), 'run-10'), maxRuns: 2, now: 10.0)
            ->record(AdversarialRunManifestEntry::fromReport($this->report('run.dataset', 1.0, 20.0), 'run-20'), maxRuns: 2, now: 20.0)
            ->record(AdversarialRunManifestEntry::fromReport($this->report('run.dataset', 1.0, 15.0), 'run-15'), maxRuns: 2, now: 21.0);

        $this->assertSame(['run-20', 'run-15'], array_map(
            static fn (AdversarialRunManifestEntry $entry): string => $entry->runId,
            $manifest->runs,
        ));
        $this->assertSame('run-20', $manifest->latest()?->runId);

        $roundTrip = AdversarialRunManifest::fromJson($manifest->toJson());
        $this->assertSame($manifest->toJson(), $roundTrip->toJson());
    }

    public function test_manifest_rejects_invalid_retention(): void
    {
        $manifest = AdversarialRunManifest::empty('adversarial.security', 1.0);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('retention must keep at least one run');

        $manifest->record(AdversarialRunManifestEntry::fromReport($this->report('run.dataset', 1.0, 2.0), 'run-1'), maxRuns: 0);
    }

    public function test_store_writes_loads_and_retains_manifest_runs(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';
        $store = new AdversarialRunManifestStore;

        try {
            $store->record($path, $this->report('run.dataset', 1.0, 10.0), maxRuns: 2, runId: 'run-10');
            $store->record($path, $this->report('run.dataset', 1.0, 20.0), maxRuns: 2, runId: 'run-20');
            $manifest = $store->record($path, $this->report('run.dataset', 1.0, 15.0), maxRuns: 2, runId: 'run-15');

            $this->assertFileExists($path);
            $this->assertSame(['run-20', 'run-15'], array_map(
                static fn (AdversarialRunManifestEntry $entry): string => $entry->runId,
                $manifest->runs,
            ));
            $this->assertSame($manifest->toJson(), $store->load($path)?->toJson());
            $this->assertSame([], glob($directory.DIRECTORY_SEPARATOR.'runs.json.tmp.*'));
        } finally {
            if (is_file($path)) {
                @unlink($path);
            }
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_defaults_manifest_name_to_report_dataset(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';

        try {
            $manifest = (new AdversarialRunManifestStore)->record($path, $this->report('versioned.dataset', 1.0, 2.0), runId: 'run-1');

            $this->assertSame('versioned.dataset', $manifest->name);
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_does_not_record_failed_regression_gate_result(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';
        $store = new AdversarialRunManifestStore;
        $gate = new AdversarialRegressionGate;

        try {
            $missing = $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 1.0, 2.0, 1.0),
                gate: $gate,
                maxDrop: 0.05,
                runId: 'run-baseline',
            );
            $failed = $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 2.0, 3.0, 0.0),
                gate: $gate,
                maxDrop: 0.05,
                maxRuns: 2,
                runId: 'run-current',
            );

            $this->assertSame(AdversarialRegressionGateResult::STATUS_MISSING_BASELINE, $missing->status);
            $this->assertTrue($missing->recorded);
            $this->assertTrue($missing->toJson()['recorded']);
            $this->assertSame(AdversarialRegressionGateResult::STATUS_FAIL, $failed->status);
            $this->assertFalse($failed->recorded);
            $this->assertSame('run-baseline', $failed->baselineRunId);
            $this->assertSame(['run-baseline'], array_map(
                static fn (AdversarialRunManifestEntry $entry): string => $entry->runId,
                $store->load($path)?->runs ?? [],
            ));
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_marks_passing_regression_gate_runs_as_recorded(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';
        $store = new AdversarialRunManifestStore;
        $gate = new AdversarialRegressionGate;

        try {
            $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 1.0, 2.0, 1.0),
                gate: $gate,
                maxDrop: 0.05,
                maxRuns: 2,
                runId: 'run-baseline',
            );

            $result = $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 2.0, 3.0, 0.98),
                gate: $gate,
                maxDrop: 0.05,
                maxRuns: 2,
                runId: 'run-current',
            );

            $this->assertSame(AdversarialRegressionGateResult::STATUS_PASS, $result->status);
            $this->assertTrue($result->recorded);
            $this->assertTrue($result->toJson()['recorded']);
            $this->assertSame(['run-current', 'run-baseline'], array_map(
                static fn (AdversarialRunManifestEntry $entry): string => $entry->runId,
                $store->load($path)?->runs ?? [],
            ));
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_rejects_invalid_regression_gate_retention_before_non_recorded_result(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';

        try {
            try {
                (new AdversarialRunManifestStore)->recordWithRegressionGate(
                    path: $path,
                    report: $this->report('run.dataset', 1.0, 2.0, 1.0, metricName: 'rouge-l'),
                    gate: new AdversarialRegressionGate,
                    maxDrop: 0.05,
                    metricTargets: ['exact-match:mean'],
                    maxRuns: 0,
                    runId: 'run-missing-metric',
                );

                $this->fail('Expected invalid retention to fail before creating the manifest directory.');
            } catch (EvalRunException $e) {
                $this->assertStringContainsString('retention must keep at least one run', $e->getMessage());
                $this->assertDirectoryDoesNotExist($directory);
            }
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_rejects_invalid_regression_gate_configuration_before_creating_directory(): void
    {
        $store = new AdversarialRunManifestStore;

        $this->assertGatedStoreCallRejectsBeforeCreatingDirectory(
            function (string $path) use ($store): void {
                $store->recordWithRegressionGate(
                    path: $path,
                    report: $this->report('run.dataset', 1.0, 2.0),
                    gate: new AdversarialRegressionGate,
                    maxDrop: 1.1,
                    runId: 'run-invalid-drop',
                );
            },
            'max drop must be a finite ratio in [0, 1]',
        );

        $this->assertGatedStoreCallRejectsBeforeCreatingDirectory(
            function (string $path) use ($store): void {
                $store->recordWithRegressionGate(
                    path: $path,
                    report: $this->report('run.dataset', 1.0, 2.0),
                    gate: new AdversarialRegressionGate,
                    maxDrop: 0.05,
                    metricTargets: ['exact-match :mean'],
                    runId: 'run-invalid-target',
                );
            },
            "metric target 'exact-match :mean' must use metric or metric:aggregate syntax",
        );
    }

    public function test_store_skips_incompatible_latest_regression_baseline(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';
        $store = new AdversarialRunManifestStore;
        $gate = new AdversarialRegressionGate;

        try {
            $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 1.0, 2.0, 1.0, 'prompt-injection'),
                gate: $gate,
                maxDrop: 0.05,
                maxRuns: 3,
                runId: 'run-prompt-baseline',
            );
            $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 2.0, 3.0, 0.0, 'ssrf'),
                gate: $gate,
                maxDrop: 0.05,
                maxRuns: 3,
                runId: 'run-ssrf-latest',
            );

            $result = $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 3.0, 4.0, 0.0, 'prompt-injection'),
                gate: $gate,
                maxDrop: 0.05,
                maxRuns: 3,
                runId: 'run-prompt-current',
            );

            $this->assertSame(AdversarialRegressionGateResult::STATUS_FAIL, $result->status);
            $this->assertSame('run-prompt-baseline', $result->baselineRunId);
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_skips_newer_regression_baseline_with_different_metrics(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';
        $store = new AdversarialRunManifestStore;
        $gate = new AdversarialRegressionGate;

        try {
            $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 1.0, 2.0, 1.0, metricName: 'exact-match'),
                gate: $gate,
                maxDrop: 0.05,
                maxRuns: 3,
                runId: 'run-exact-baseline',
            );
            $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 2.0, 3.0, 0.0, metricName: 'rouge-l'),
                gate: $gate,
                maxDrop: 0.05,
                maxRuns: 3,
                runId: 'run-rouge-latest',
            );

            $result = $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 3.0, 4.0, 0.0, metricName: 'exact-match'),
                gate: $gate,
                maxDrop: 0.05,
                metricTargets: ['exact-match:mean'],
                maxRuns: 3,
                runId: 'run-exact-current',
            );

            $this->assertSame(AdversarialRegressionGateResult::STATUS_FAIL, $result->status);
            $this->assertSame('run-exact-baseline', $result->baselineRunId);
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_does_not_record_regression_gate_run_when_configured_metric_is_missing(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';
        $store = new AdversarialRunManifestStore;
        $gate = new AdversarialRegressionGate;

        try {
            $result = $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 1.0, 2.0, 1.0, metricName: 'rouge-l'),
                gate: $gate,
                maxDrop: 0.05,
                metricTargets: ['exact-match:mean'],
                runId: 'run-missing-metric',
            );

            $this->assertSame(AdversarialRegressionGateResult::STATUS_FAIL, $result->status);
            $this->assertFalse($result->recorded);
            $this->assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_does_not_record_regression_gate_run_with_metric_failures(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';
        $store = new AdversarialRunManifestStore;
        $gate = new AdversarialRegressionGate;

        try {
            $result = $store->recordWithRegressionGate(
                path: $path,
                report: $this->failedReport('run.dataset', 1.0, 2.0),
                gate: $gate,
                maxDrop: 0.05,
                metricTargets: ['exact-match:mean'],
                runId: 'run-with-failures',
            );

            $this->assertSame(AdversarialRegressionGateResult::STATUS_MISSING_BASELINE, $result->status);
            $this->assertFalse($result->recorded);
            $this->assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_skips_failed_manifest_entries_as_regression_baselines(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';
        $store = new AdversarialRunManifestStore;
        $gate = new AdversarialRegressionGate;

        try {
            $store->record(
                path: $path,
                report: $this->report('run.dataset', 1.0, 2.0, 1.0),
                maxRuns: 3,
                runId: 'run-clean-baseline',
            );
            $store->record(
                path: $path,
                report: $this->failedReport('run.dataset', 2.0, 3.0),
                maxRuns: 3,
                runId: 'run-failed-latest',
            );

            $result = $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 3.0, 4.0, 0.0),
                gate: $gate,
                maxDrop: 0.05,
                metricTargets: ['exact-match:mean'],
                maxRuns: 3,
                runId: 'run-current',
            );

            $this->assertSame(AdversarialRegressionGateResult::STATUS_FAIL, $result->status);
            $this->assertSame('run-clean-baseline', $result->baselineRunId);
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_retention_preserves_latest_failure_free_baseline_over_failed_plain_runs(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';
        $store = new AdversarialRunManifestStore;

        try {
            $store->record(
                path: $path,
                report: $this->report('run.dataset', 1.0, 2.0, 1.0),
                maxRuns: 1,
                runId: 'run-clean-baseline',
            );
            $store->record(
                path: $path,
                report: $this->failedReport('run.dataset', 2.0, 3.0),
                maxRuns: 1,
                runId: 'run-failed-latest',
            );

            $this->assertSame(['run-clean-baseline'], array_map(
                static fn (AdversarialRunManifestEntry $entry): string => $entry->runId,
                $store->load($path)?->runs ?? [],
            ));

            $result = $store->recordWithRegressionGate(
                path: $path,
                report: $this->report('run.dataset', 3.0, 4.0, 0.0),
                gate: new AdversarialRegressionGate,
                maxDrop: 0.05,
                metricTargets: ['exact-match:mean'],
                maxRuns: 1,
                runId: 'run-current',
            );

            $this->assertSame(AdversarialRegressionGateResult::STATUS_FAIL, $result->status);
            $this->assertSame('run-clean-baseline', $result->baselineRunId);
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_rejects_manifest_name_mismatch(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';
        $store = new AdversarialRunManifestStore;

        try {
            $store->record($path, $this->report('first.dataset', 1.0, 2.0), manifestName: 'first.dataset', runId: 'run-1');

            $this->expectException(EvalRunException::class);
            $this->expectExceptionMessage("belongs to manifest 'first.dataset', not 'second.dataset'");

            $store->record($path, $this->report('second.dataset', 2.0, 3.0), manifestName: 'second.dataset', runId: 'run-2');
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_rejects_manifest_name_mismatch_for_regression_gate_writes(): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';
        $store = new AdversarialRunManifestStore;

        try {
            $store->record($path, $this->report('first.dataset', 1.0, 2.0), manifestName: 'first.dataset', runId: 'run-1');

            try {
                $store->recordWithRegressionGate(
                    path: $path,
                    report: $this->report('second.dataset', 2.0, 3.0),
                    gate: new AdversarialRegressionGate,
                    maxDrop: 0.05,
                    manifestName: 'second.dataset',
                    runId: 'run-2',
                );

                $this->fail('Expected manifest-name mismatch to fail for gated writes.');
            } catch (EvalRunException $e) {
                $this->assertStringContainsString("belongs to manifest 'first.dataset', not 'second.dataset'", $e->getMessage());
            }

            $this->assertSame(['run-1'], array_map(
                static fn (AdversarialRunManifestEntry $entry): string => $entry->runId,
                $store->load($path)?->runs ?? [],
            ));
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    public function test_store_rejects_invalid_json(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'eval-adv-manifest-');
        $this->assertNotFalse($path);

        try {
            file_put_contents($path, '{not-json');

            $this->expectException(EvalRunException::class);
            $this->expectExceptionMessage('contains invalid JSON');

            (new AdversarialRunManifestStore)->load($path);
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
        }
    }

    /**
     * @param  callable(string): void  $call
     */
    private function assertGatedStoreCallRejectsBeforeCreatingDirectory(callable $call, string $expectedMessage): void
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-adv-manifest-'.uniqid('', true);
        $path = $directory.DIRECTORY_SEPARATOR.'runs.json';

        try {
            try {
                $call($path);

                $this->fail('Expected invalid gate configuration to fail before creating the manifest directory.');
            } catch (EvalRunException $e) {
                $this->assertStringContainsString($expectedMessage, $e->getMessage());
                $this->assertDirectoryDoesNotExist($directory);
            }
        } finally {
            @unlink($path);
            @unlink($path.'.lock');
            if (is_dir($directory)) {
                @rmdir($directory);
            }
        }
    }

    private function report(
        string $datasetName,
        float $startedAt,
        float $finishedAt,
        float $score = 1.0,
        string $category = 'prompt-injection',
        string $metricName = 'exact-match',
    ): EvalReport {
        return new EvalReport(
            datasetName: $datasetName,
            sampleResults: [
                new SampleResult(
                    sample: $this->sample($category),
                    actualOutput: $score >= 0.5 ? 'safe' : 'unsafe',
                    metricScores: [$metricName => new MetricScore($score)],
                ),
            ],
            failures: [],
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );
    }

    private function failedReport(string $datasetName, float $startedAt, float $finishedAt): EvalReport
    {
        return new EvalReport(
            datasetName: $datasetName,
            sampleResults: [
                new SampleResult(
                    sample: $this->sample(),
                    actualOutput: 'unsafe',
                    metricScores: ['exact-match' => new MetricScore(0.0)],
                ),
            ],
            failures: [
                new SampleFailure('adv.prompt-injection', 'exact-match', 'metric failed'),
            ],
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );
    }

    private function sample(string $category = 'prompt-injection'): DatasetSample
    {
        return new DatasetSample(
            id: 'adv.'.$category,
            input: [],
            expectedOutput: 'safe',
            metadata: [
                'adversarial' => [
                    'category' => $category,
                    'label' => $category,
                    'severity' => 'high',
                    'compliance_frameworks' => ['OWASP LLM', 'NIST AI RMF'],
                ],
            ],
        );
    }
}
