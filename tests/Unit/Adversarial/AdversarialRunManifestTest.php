<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Adversarial;

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
        }
    }

    private function report(string $datasetName, float $startedAt, float $finishedAt, float $score = 1.0): EvalReport
    {
        return new EvalReport(
            datasetName: $datasetName,
            sampleResults: [
                new SampleResult(
                    sample: $this->sample(),
                    actualOutput: $score >= 0.5 ? 'safe' : 'unsafe',
                    metricScores: ['exact-match' => new MetricScore($score)],
                ),
            ],
            failures: [],
            startedAt: $startedAt,
            finishedAt: $finishedAt,
        );
    }

    private function sample(): DatasetSample
    {
        return new DatasetSample(
            id: 'adv.prompt-injection',
            input: [],
            expectedOutput: 'safe',
            metadata: [
                'adversarial' => [
                    'category' => 'prompt-injection',
                    'label' => 'Prompt injection',
                    'severity' => 'high',
                    'compliance_frameworks' => ['OWASP LLM', 'NIST AI RMF'],
                ],
            ],
        );
    }
}
