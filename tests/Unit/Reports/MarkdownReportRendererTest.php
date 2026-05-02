<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Reports;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\MarkdownReportRenderer;
use Padosoft\EvalHarness\Reports\SampleFailure;
use Padosoft\EvalHarness\Reports\SampleResult;
use PHPUnit\Framework\TestCase;

final class MarkdownReportRendererTest extends TestCase
{
    public function test_basic_report_contains_dataset_and_aggregates(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(id: 's1', input: ['q' => 'q'], expectedOutput: 'e'),
                    actualOutput: 'e',
                    metricScores: ['exact-match' => new MetricScore(1.0)],
                ),
                new SampleResult(
                    sample: new DatasetSample(id: 's2', input: ['q' => 'q'], expectedOutput: 'e'),
                    actualOutput: 'x',
                    metricScores: ['exact-match' => new MetricScore(0.0)],
                ),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 1.5,
        );

        $md = (new MarkdownReportRenderer)->render($report);

        $this->assertStringContainsString('# Eval report — demo', $md);
        $this->assertStringContainsString('| exact-match |', $md);
        $this->assertStringContainsString('## Macro-F1', $md);
        $this->assertStringNotContainsString('## Failures', $md);
    }

    public function test_failures_section_appears_when_failures_present(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [],
            failures: [new SampleFailure('s1', 'llm-as-judge', 'timeout')],
            startedAt: 0.0,
            finishedAt: 0.0,
        );

        $md = (new MarkdownReportRenderer)->render($report);

        $this->assertStringContainsString('## Failures', $md);
        $this->assertStringContainsString('`s1`', $md);
        $this->assertStringContainsString('llm-as-judge', $md);
        $this->assertStringContainsString('timeout', $md);
    }

    public function test_output_ends_with_newline(): void
    {
        $report = new EvalReport('x', [], [], 0.0, 0.0);
        $md = (new MarkdownReportRenderer)->render($report);
        // POSIX convention: text files end in a newline. Markdown
        // conventionally has a blank line between sections, so a
        // double-newline tail is acceptable; the only contract is
        // "must end with at least one \n".
        $this->assertSame("\n", substr($md, -1));
    }
}
