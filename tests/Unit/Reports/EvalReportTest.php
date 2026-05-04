<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Reports;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\ReportSchemaException;
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

    public function test_constructor_rejects_unsupported_report_schema_version(): void
    {
        $this->expectException(ReportSchemaException::class);
        $this->expectExceptionMessage('unsupported schema version');

        new EvalReport(
            datasetName: 'demo',
            sampleResults: [],
            failures: [],
            startedAt: 0.0,
            finishedAt: 0.0,
            schemaVersion: 'eval-harness.report.v999',
        );
    }

    public function test_constructor_rejects_unsupported_dataset_schema_version(): void
    {
        $this->expectException(ReportSchemaException::class);
        $this->expectExceptionMessage('unsupported dataset schema version');

        new EvalReport(
            datasetName: 'demo',
            sampleResults: [],
            failures: [],
            startedAt: 0.0,
            finishedAt: 0.0,
            datasetSchemaVersion: 'eval-harness.dataset.v999',
        );
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

    public function test_cohort_summaries_group_by_tags_and_missing_tags(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(
                        id: 's1',
                        input: [],
                        expectedOutput: 'a',
                        metadata: ['tags' => ['geography', 'easy']],
                    ),
                    actualOutput: 'a',
                    metricScores: ['exact-match' => new MetricScore(1.0)],
                ),
                new SampleResult(
                    sample: new DatasetSample(
                        id: 's2',
                        input: [],
                        expectedOutput: 'b',
                        metadata: ['tags' => 'geography'],
                    ),
                    actualOutput: 'x',
                    metricScores: ['exact-match' => new MetricScore(0.0)],
                ),
                new SampleResult(
                    sample: new DatasetSample(id: 's3', input: [], expectedOutput: 'c'),
                    actualOutput: 'c',
                    metricScores: ['exact-match' => new MetricScore(0.5)],
                ),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 1.0,
        );

        $cohorts = $report->cohortSummaries();

        $this->assertSame(['easy', 'geography', null], array_column($cohorts, 'name'));
        $this->assertSame(1, $cohorts[0]['sample_count']);
        $this->assertSame(2, $cohorts[1]['sample_count']);
        $this->assertSame('(untagged)', $cohorts[2]['label']);
        $this->assertTrue($cohorts[2]['is_untagged']);
        $this->assertEqualsWithDelta(1.0, $cohorts[0]['metrics']['exact-match']['mean'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $cohorts[1]['metrics']['exact-match']['mean'], 1e-9);
        $this->assertEqualsWithDelta(0.5, $cohorts[2]['metrics']['exact-match']['mean'], 1e-9);
    }

    public function test_literal_untagged_tag_does_not_merge_with_missing_tags(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(
                        id: 's1',
                        input: [],
                        expectedOutput: 'a',
                        metadata: ['tags' => ['__untagged__']],
                    ),
                    actualOutput: 'a',
                    metricScores: ['exact-match' => new MetricScore(1.0)],
                ),
                new SampleResult(
                    sample: new DatasetSample(id: 's2', input: [], expectedOutput: 'b'),
                    actualOutput: 'x',
                    metricScores: ['exact-match' => new MetricScore(0.0)],
                ),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 1.0,
        );

        $cohorts = $report->cohortSummaries();

        $this->assertSame(['__untagged__', null], array_column($cohorts, 'name'));
        $this->assertFalse($cohorts[0]['is_untagged']);
        $this->assertTrue($cohorts[1]['is_untagged']);
        $this->assertEqualsWithDelta(1.0, $cohorts[0]['metrics']['exact-match']['mean'], 1e-9);
        $this->assertEqualsWithDelta(0.0, $cohorts[1]['metrics']['exact-match']['mean'], 1e-9);
    }

    public function test_histogram_places_boundary_scores_in_stable_buckets(): void
    {
        $report = $this->reportWithScores([0.0, 0.05, 0.5, 1.0]);

        $histogram = $report->histogramForMetric('exact-match', 2);

        $this->assertSame([
            ['min' => 0.0, 'max' => 0.5, 'count' => 2],
            ['min' => 0.5, 'max' => 1.0, 'count' => 2],
        ], $histogram);
    }

    public function test_histogram_for_metric_without_scores_returns_zero_buckets(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(id: 's1', input: [], expectedOutput: 'a'),
                    actualOutput: 'a',
                    metricScores: [],
                ),
            ],
            failures: [new SampleFailure(sampleId: 's1', metricName: 'llm-as-judge', error: 'timeout')],
            startedAt: 0.0,
            finishedAt: 1.0,
        );

        $this->assertSame([
            ['min' => 0.0, 'max' => 0.5, 'count' => 0],
            ['min' => 0.5, 'max' => 1.0, 'count' => 0],
        ], $report->histogramForMetric('llm-as-judge', 2));
    }

    public function test_histogram_final_bucket_max_is_exactly_one_for_fractional_widths(): void
    {
        $histogram = $this->reportWithScores([1.0])->histogramForMetric('exact-match', 3);

        $this->assertSame(1.0, $histogram[2]['max']);
        $this->assertSame(1, $histogram[2]['count']);
    }

    public function test_histogram_boundaries_are_rounded_for_json_stability(): void
    {
        $histogram = $this->reportWithScores([0.7 - 0.4])->histogramForMetric('exact-match', 10);

        $this->assertSame(0.3, $histogram[2]['max']);
        $this->assertSame(0.3, $histogram[3]['min']);
        $this->assertSame(0, $histogram[2]['count']);
        $this->assertSame(1, $histogram[3]['count']);
    }

    public function test_histogram_assigns_repeating_decimal_boundary_to_next_bucket(): void
    {
        $histogram = $this->reportWithScores([1.0 / 3.0])->histogramForMetric('exact-match', 3);

        $this->assertSame(0, $histogram[0]['count']);
        $this->assertSame(1, $histogram[1]['count']);
    }

    public function test_usage_summary_aggregates_structured_metric_details(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(id: 's1', input: [], expectedOutput: 'a'),
                    actualOutput: 'a',
                    metricScores: [
                        'llm-as-judge' => new MetricScore(1.0, [
                            'usage' => [
                                'prompt_tokens' => 10,
                                'completion_tokens' => 4,
                                'total_tokens' => 14,
                                'cost_usd' => 0.001,
                                'latency_ms' => 120.5,
                            ],
                        ]),
                    ],
                ),
                new SampleResult(
                    sample: new DatasetSample(id: 's2', input: [], expectedOutput: 'b'),
                    actualOutput: 'b',
                    metricScores: [
                        'refusal-quality' => new MetricScore(1.0, [
                            'usage' => [
                                'prompt_tokens' => '8',
                                'completion_tokens' => '2',
                                'total_cost_usd' => '0.0005',
                                'latency_ms' => 80,
                            ],
                        ]),
                    ],
                ),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 1.0,
        );

        $this->assertSame([
            'observations' => 2,
            'prompt_tokens' => 18,
            'completion_tokens' => 6,
            'total_tokens' => 14,
            'cost_usd' => 0.0015,
            'reported' => [
                'prompt_tokens' => 2,
                'completion_tokens' => 2,
                'total_tokens' => 1,
                'cost_usd' => 2,
                'latency_ms' => 2,
            ],
            'latency_ms' => [
                'count' => 2,
                'total' => 200.5,
                'mean' => 100.25,
                'max' => 120.5,
            ],
        ], $report->usageSummary());
    }

    public function test_usage_summary_ignores_malformed_usage_details(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(id: 's1', input: [], expectedOutput: 'a'),
                    actualOutput: 'a',
                    metricScores: [
                        'custom' => new MetricScore(1.0, [
                            'usage' => [
                                'prompt_tokens' => -1,
                                'completion_tokens' => 'nan',
                                'cost_usd' => '1e309',
                                'latency_ms' => INF,
                            ],
                        ]),
                    ],
                ),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 1.0,
        );

        $this->assertSame(0, $report->usageSummary()['observations']);
    }

    public function test_usage_summary_distinguishes_unreported_fields_from_reported_zeroes(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(id: 's1', input: [], expectedOutput: 'a'),
                    actualOutput: 'a',
                    metricScores: [
                        'custom' => new MetricScore(1.0, [
                            'usage' => [
                                'completion_tokens' => 0,
                                'latency_ms' => 42.0,
                            ],
                        ]),
                    ],
                ),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 1.0,
        );

        $summary = $report->usageSummary();

        $this->assertSame(1, $summary['observations']);
        $this->assertSame(0, $summary['prompt_tokens']);
        $this->assertSame(0, $summary['completion_tokens']);
        $this->assertSame(0, $summary['total_tokens']);
        $this->assertSame(0, $summary['reported']['prompt_tokens']);
        $this->assertSame(1, $summary['reported']['completion_tokens']);
        $this->assertSame(0, $summary['reported']['total_tokens']);
        $this->assertSame(1, $summary['reported']['latency_ms']);
        $this->assertSame(42.0, $summary['latency_ms']['mean']);
    }
}
