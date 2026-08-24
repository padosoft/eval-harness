<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Reports;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\JsonReportRenderer;
use Padosoft\EvalHarness\Reports\SampleFailure;
use Padosoft\EvalHarness\Reports\SampleResult;
use PHPUnit\Framework\TestCase;

final class SampleAggregationTest extends TestCase
{
    public function test_a_single_execution_run_reports_one_repetition_per_row(): void
    {
        $report = $this->report([
            $this->sampleResult('a', 1.0),
            $this->sampleResult('b', 0.0),
        ]);

        $this->assertSame(1, $report->repetitions());
        $this->assertSame(2, $report->totalRows());
        $this->assertSame(2, $report->totalSamples());
        $this->assertSame(0.5, $report->runPassRate());
    }

    public function test_repetitions_are_grouped_into_one_aggregate_per_row(): void
    {
        $report = $this->report([
            $this->sampleResult('a', 1.0, 0),
            $this->sampleResult('a', 0.0, 1),
            $this->sampleResult('a', 1.0, 2),
            $this->sampleResult('b', 1.0, 0),
            $this->sampleResult('b', 1.0, 1),
            $this->sampleResult('b', 1.0, 2),
        ]);

        $this->assertSame(3, $report->repetitions());
        $this->assertSame(2, $report->totalRows());
        $this->assertSame(6, $report->totalSamples());

        $aggregates = $report->sampleAggregates();
        $this->assertCount(2, $aggregates);

        [$a, $b] = $aggregates;

        $this->assertSame('a', $a->sampleId);
        $this->assertSame(3, $a->repetitions);
        $this->assertSame(2, $a->passed);
        $this->assertEqualsWithDelta(2 / 3, $a->passRate, 0.000001);
        $this->assertTrue($a->isUnstable());

        $this->assertSame('b', $b->sampleId);
        $this->assertSame(3, $b->passed);
        $this->assertSame(1.0, $b->passRate);
        $this->assertFalse($b->isUnstable());
    }

    public function test_score_spread_is_reported_per_row(): void
    {
        $report = $this->report([
            $this->sampleResult('a', 1.0, 0),
            $this->sampleResult('a', 0.0, 1),
        ]);

        $aggregate = $report->sampleAggregates()[0];

        $this->assertSame(0.5, $aggregate->scoreMean);
        $this->assertSame(0.5, $aggregate->scoreStddev);
        $this->assertSame(0.5, $aggregate->metrics['exact-match']['mean']);
        $this->assertSame(0.5, $aggregate->metrics['exact-match']['stddev']);
        $this->assertSame(0.0, $aggregate->metrics['exact-match']['min']);
        $this->assertSame(1.0, $aggregate->metrics['exact-match']['max']);
        $this->assertSame(2, $aggregate->metrics['exact-match']['observations']);
    }

    public function test_a_stable_row_has_zero_spread(): void
    {
        $report = $this->report([
            $this->sampleResult('a', 0.9, 0),
            $this->sampleResult('a', 0.9, 1),
            $this->sampleResult('a', 0.9, 2),
        ]);

        $aggregate = $report->sampleAggregates()[0];

        $this->assertSame(0.9, $aggregate->scoreMean);
        $this->assertSame(0.0, $aggregate->scoreStddev);
        $this->assertFalse($aggregate->isUnstable());
    }

    /**
     * A metric that threw on one repetition must not be able to look like a
     * pass on that repetition — otherwise a flaky judge quietly inflates the
     * pass rate of the very row it failed to grade.
     */
    public function test_a_failed_metric_marks_only_its_own_repetition(): void
    {
        $report = new EvalReport(
            datasetName: 'x',
            sampleResults: [
                $this->sampleResult('a', 1.0, 0),
                new SampleResult(new DatasetSample('a', ['q' => 'q'], 'e'), 'out', [], 1),
                $this->sampleResult('a', 1.0, 2),
            ],
            failures: [new SampleFailure('a', 'llm-as-judge', 'timeout', [], 1)],
            startedAt: 0.0,
            finishedAt: 1.0,
        );

        $aggregate = $report->sampleAggregates()[0];

        $this->assertSame(3, $aggregate->repetitions);
        $this->assertSame(2, $aggregate->passed);
        $this->assertSame(1, $aggregate->errored);
        $this->assertTrue($aggregate->isUnstable());
    }

    public function test_a_repetition_passes_only_when_every_metric_passes(): void
    {
        $sample = new DatasetSample('a', ['q' => 'q'], 'e');

        $report = new EvalReport(
            datasetName: 'x',
            sampleResults: [
                new SampleResult($sample, 'out', [
                    'exact-match' => new MetricScore(1.0),
                    'llm-as-judge' => new MetricScore(0.2),
                ], 0),
                new SampleResult($sample, 'out', [
                    'exact-match' => new MetricScore(1.0),
                    'llm-as-judge' => new MetricScore(0.9),
                ], 1),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 1.0,
        );

        $aggregate = $report->sampleAggregates()[0];

        $this->assertSame(1, $aggregate->passed);
        $this->assertSame(0.5, $aggregate->passRate);
    }

    public function test_unstable_rows_are_listed_separately(): void
    {
        $report = $this->report([
            $this->sampleResult('stable', 1.0, 0),
            $this->sampleResult('stable', 1.0, 1),
            $this->sampleResult('flaky', 1.0, 0),
            $this->sampleResult('flaky', 0.0, 1),
        ]);

        $unstable = $report->unstableSamples();

        $this->assertCount(1, $unstable);
        $this->assertSame('flaky', $unstable[0]->sampleId);
    }

    public function test_precision_is_reported_for_the_run(): void
    {
        $report = $this->report([
            $this->sampleResult('a', 1.0, 0),
            $this->sampleResult('a', 0.0, 1),
            $this->sampleResult('a', 1.0, 2),
        ]);

        $precision = $report->precision();

        $this->assertSame(3, $precision['repetitions']);
        $this->assertFalse($precision['target_resolvable']);
        $this->assertGreaterThan(3, $precision['required_repetitions']);
    }

    public function test_json_report_carries_the_new_blocks(): void
    {
        $report = $this->report([
            $this->sampleResult('a', 1.0, 0),
            $this->sampleResult('a', 0.0, 1),
        ]);

        $json = (new JsonReportRenderer)->render($report);

        $this->assertSame(2, $json['total_samples']);
        $this->assertSame(1, $json['total_rows']);
        $this->assertSame(2, $json['repetitions']);
        $this->assertSame(0.5, $json['pass_rate']);
        $this->assertSame(0, $json['samples'][0]['repetition']);
        $this->assertSame(1, $json['samples'][1]['repetition']);

        $this->assertCount(1, $json['sample_aggregates']);
        $this->assertSame('a', $json['sample_aggregates'][0]['id']);
        $this->assertTrue($json['sample_aggregates'][0]['unstable']);
        $this->assertSame(0.95, $json['sample_aggregates'][0]['pass_rate_ci']['confidence']);
        $this->assertArrayHasKey('summary', $json['precision']);
    }

    public function test_markdown_report_explains_the_sampling(): void
    {
        $report = $this->report([
            $this->sampleResult('a', 1.0, 0),
            $this->sampleResult('a', 0.0, 1),
            $this->sampleResult('a', 1.0, 2),
        ]);

        $markdown = $report->toMarkdown();

        $this->assertStringContainsString('## Sampling', $markdown);
        $this->assertStringContainsString('### Unstable rows', $markdown);
        $this->assertStringContainsString('not distinguishable from noise', $markdown);
    }

    public function test_an_empty_report_does_not_divide_by_zero(): void
    {
        $report = $this->report([]);

        $this->assertSame(0, $report->repetitions());
        $this->assertSame(0, $report->totalRows());
        $this->assertSame(0.0, $report->runPassRate());
        $this->assertSame([], $report->sampleAggregates());
        $this->assertFalse($report->precision()['target_resolvable']);
    }

    /**
     * @param  list<SampleResult>  $results
     */
    private function report(array $results): EvalReport
    {
        return new EvalReport(
            datasetName: 'x',
            sampleResults: $results,
            failures: [],
            startedAt: 0.0,
            finishedAt: 1.0,
        );
    }

    private function sampleResult(string $id, float $score, int $repetition = 0): SampleResult
    {
        return new SampleResult(
            sample: new DatasetSample($id, ['question' => 'q'], 'expected'),
            actualOutput: 'out',
            metricScores: ['exact-match' => new MetricScore($score)],
            repetition: $repetition,
        );
    }
}
