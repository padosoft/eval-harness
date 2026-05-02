<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Reports;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\SampleFailure;
use Padosoft\EvalHarness\Reports\SampleResult;
use PHPUnit\Framework\TestCase;

final class EvalReportTest extends TestCase
{
    private function reportWithScores(array $scores): EvalReport
    {
        $sampleResults = [];
        foreach ($scores as $i => $score) {
            $sample = new DatasetSample(
                id: 's'.$i,
                input: ['q' => 'q'.$i],
                expectedOutput: 'expected',
            );
            $sampleResults[] = new SampleResult(
                sample: $sample,
                actualOutput: 'actual',
                metricScores: ['exact-match' => new MetricScore(score: $score)],
            );
        }

        return new EvalReport(
            datasetName: 'demo',
            sampleResults: $sampleResults,
            failures: [],
            startedAt: 1.0,
            finishedAt: 3.5,
        );
    }

    public function test_duration_is_finished_minus_started(): void
    {
        $report = $this->reportWithScores([1.0]);
        $this->assertEqualsWithDelta(2.5, $report->durationSeconds(), 1e-9);
    }

    public function test_mean_score_strict_monotonic_fixture(): void
    {
        // Strictly different values so a wrong reduction (e.g. min/max
        // instead of mean) would produce a different number.
        $report = $this->reportWithScores([0.2, 0.4, 0.9]);
        $this->assertEqualsWithDelta((0.2 + 0.4 + 0.9) / 3.0, $report->meanScore('exact-match'), 1e-9);
    }

    public function test_p50_p95_on_known_distribution(): void
    {
        $report = $this->reportWithScores([0.0, 0.25, 0.5, 0.75, 1.0]);
        $this->assertEqualsWithDelta(0.5, $report->percentile('exact-match', 50.0), 1e-9);
        // p95 across 5 values: rank = 0.95 * 4 = 3.8 → between
        // values[3] (0.75) and values[4] (1.0) at weight 0.8 → 0.95.
        $this->assertEqualsWithDelta(0.95, $report->percentile('exact-match', 95.0), 1e-9);
    }

    public function test_macro_f1_uses_05_threshold(): void
    {
        // 3 of 5 above threshold → 0.6.
        $report = $this->reportWithScores([0.1, 0.4, 0.5, 0.6, 0.9]);
        $this->assertEqualsWithDelta(0.6, $report->macroF1('exact-match'), 1e-9);
    }

    public function test_macro_f1_no_metric_returns_zero(): void
    {
        $report = new EvalReport('empty', [], [], 0.0, 0.0);
        $this->assertSame(0.0, $report->macroF1());
    }

    public function test_metric_names_unique(): void
    {
        $report = $this->reportWithScores([0.1, 0.2, 0.3]);
        $this->assertSame(['exact-match'], $report->metricNames());
    }

    public function test_unknown_metric_aggregates_to_zero(): void
    {
        $report = $this->reportWithScores([0.5]);
        $this->assertSame(0.0, $report->meanScore('does-not-exist'));
        $this->assertSame(0.0, $report->percentile('does-not-exist', 50.0));
    }

    /**
     * Regression: a metric that fails on every sample previously
     * disappeared from metricNames() because the method only looked
     * at successful metricScores. metricNames() now also walks
     * $failures so a total outage is visible in the aggregate row
     * (with 0.0 values + a non-empty failures list) instead of
     * looking like the metric was never configured.
     */
    public function test_metric_names_includes_metrics_that_failed_on_every_sample(): void
    {
        $sample1 = new DatasetSample(id: 's1', input: [], expectedOutput: 'a');
        $sample2 = new DatasetSample(id: 's2', input: [], expectedOutput: 'b');

        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(sample: $sample1, actualOutput: 'a', metricScores: []),
                new SampleResult(sample: $sample2, actualOutput: 'b', metricScores: []),
            ],
            failures: [
                new SampleFailure(sampleId: 's1', metricName: 'cosine-embedding', error: 'HTTP 500'),
                new SampleFailure(sampleId: 's2', metricName: 'cosine-embedding', error: 'HTTP 500'),
            ],
            startedAt: 0.0,
            finishedAt: 0.5,
        );

        $this->assertSame(['cosine-embedding'], $report->metricNames());
        // Aggregates collapse to 0.0 when every sample failed — the
        // important property is that the metric is REPRESENTED.
        $this->assertSame(0.0, $report->meanScore('cosine-embedding'));
        $this->assertSame(2, $report->totalFailures());
    }

    public function test_metric_names_merges_successful_and_failed(): void
    {
        $sample1 = new DatasetSample(id: 's1', input: [], expectedOutput: 'a');
        $sample2 = new DatasetSample(id: 's2', input: [], expectedOutput: 'b');

        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(
                    sample: $sample1,
                    actualOutput: 'a',
                    metricScores: ['exact-match' => new MetricScore(score: 1.0)],
                ),
                new SampleResult(sample: $sample2, actualOutput: 'b', metricScores: []),
            ],
            failures: [
                new SampleFailure(sampleId: 's2', metricName: 'cosine-embedding', error: 'HTTP 500'),
            ],
            startedAt: 0.0,
            finishedAt: 0.5,
        );

        $names = $report->metricNames();
        sort($names);
        $this->assertSame(['cosine-embedding', 'exact-match'], $names);
    }
}
