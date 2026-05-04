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
        $this->assertStringContainsString('## Summary', $md);
        $this->assertStringContainsString('| exact-match |', $md);
        $this->assertStringContainsString('## Macro-F1', $md);
        $this->assertStringContainsString('## Cohorts by metadata.tags', $md);
        $this->assertStringNotContainsString('## Adversarial coverage', $md);
        $this->assertStringContainsString('## Score histograms', $md);
        $this->assertStringContainsString('(untagged)', $md);
        $this->assertStringContainsString('| 0.0-0.1 |', $md);
        $this->assertStringNotContainsString('## Usage summary', $md);
        $this->assertStringNotContainsString('## Failures', $md);
    }

    public function test_usage_summary_section_appears_when_usage_details_exist(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(id: 's1', input: [], expectedOutput: 'e'),
                    actualOutput: 'e',
                    metricScores: [
                        'llm-as-judge' => new MetricScore(1.0, [
                            'usage' => [
                                'prompt_tokens' => 7,
                                'completion_tokens' => 3,
                                'total_tokens' => 10,
                                'cost_usd' => 0.001,
                                'latency_ms' => 50,
                            ],
                        ]),
                    ],
                ),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 1.0,
        );

        $md = (new MarkdownReportRenderer)->render($report);

        $this->assertStringContainsString('## Usage summary', $md);
        $this->assertStringContainsString('| observations | prompt tokens | completion tokens | total tokens | cost USD | mean latency ms | max latency ms |', $md);
        $this->assertStringContainsString('| 1 | 7 | 3 | 10 | 0.001000 | 50.00 | 50.00 |', $md);
    }

    public function test_usage_summary_renders_unreported_fields_as_not_available(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(id: 's1', input: [], expectedOutput: 'e'),
                    actualOutput: 'e',
                    metricScores: [
                        'latency-only' => new MetricScore(1.0, [
                            'usage' => [
                                'latency_ms' => 50,
                            ],
                        ]),
                    ],
                ),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 1.0,
        );

        $md = (new MarkdownReportRenderer)->render($report);

        $this->assertStringContainsString('| 1 | n/a | n/a | n/a | n/a | 50.00 | 50.00 |', $md);
    }

    public function test_adversarial_coverage_section_appears_when_adversarial_metadata_exists(): void
    {
        $report = new EvalReport(
            datasetName: 'adversarial.security.v1',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(
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
                    ),
                    actualOutput: 'safe',
                    metricScores: ['exact-match' => new MetricScore(1.0)],
                ),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 1.0,
        );

        $md = (new MarkdownReportRenderer)->render($report);

        $this->assertStringContainsString('## Adversarial coverage', $md);
        $this->assertStringContainsString('| category | label | severity | samples | frameworks | metric | mean | p50 | p95 | pass-rate (>= 0.5) |', $md);
        $this->assertStringContainsString('| prompt-injection | Prompt injection | high | 1 | NIST AI RMF, OWASP LLM | exact-match | 1.0000 | 1.0000 | 1.0000 | 1.0000 |', $md);
        $this->assertStringContainsString('### Compliance frameworks', $md);
        $this->assertStringContainsString('| NIST AI RMF | 1 | prompt-injection |', $md);
        $this->assertStringContainsString('| OWASP LLM | 1 | prompt-injection |', $md);
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
        $this->assertStringContainsString('<code>s1</code>', $md);
        $this->assertStringContainsString('llm-as-judge', $md);
        $this->assertStringContainsString('timeout', $md);
    }

    public function test_failures_section_escapes_markdown_sensitive_values(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [],
            failures: [new SampleFailure("s`1\nnext", 'metric`name', "boom\n`code`")],
            startedAt: 0.0,
            finishedAt: 0.0,
        );

        $md = (new MarkdownReportRenderer)->render($report);

        $this->assertStringContainsString('sample <code>s`1 next</code>', $md);
        $this->assertStringContainsString('metric <code>metric`name</code>', $md);
        $this->assertStringContainsString('boom \\`code\\`', $md);
        $this->assertStringNotContainsString("s`1\nnext", $md);
    }

    public function test_table_cells_escape_user_controlled_labels(): void
    {
        $report = new EvalReport(
            datasetName: 'demo',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(
                        id: 's1',
                        input: [],
                        expectedOutput: 'e',
                        metadata: ['tags' => ["geo|bad\nline`tick"]],
                    ),
                    actualOutput: 'e',
                    metricScores: ["exact|match\nbad`metric" => new MetricScore(1.0)],
                ),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 0.0,
        );

        $md = (new MarkdownReportRenderer)->render($report);

        $this->assertStringContainsString('geo\\|bad line\\`tick', $md);
        $this->assertStringContainsString('exact\\|match bad\\`metric', $md);
        $this->assertStringContainsString('### exact|match bad\\`metric', $md);
        $this->assertStringNotContainsString("geo|bad\nline", $md);
    }

    public function test_renderer_html_escapes_user_controlled_report_text(): void
    {
        $report = new EvalReport(
            datasetName: 'demo <script>alert("dataset")</script>',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(
                        id: 's1',
                        input: [],
                        expectedOutput: 'e',
                        metadata: ['tags' => ['<img src=x onerror=alert(1)>|geo']],
                    ),
                    actualOutput: 'e',
                    metricScores: ['<metric onmouseover="x">|quality' => new MetricScore(1.0)],
                ),
            ],
            failures: [new SampleFailure('s<1>', 'm<2>', '<script>alert("failure")</script>')],
            startedAt: 0.0,
            finishedAt: 0.0,
        );

        $md = (new MarkdownReportRenderer)->render($report);

        $this->assertStringContainsString('demo &lt;script&gt;alert(&quot;dataset&quot;)&lt;/script&gt;', $md);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;\\|geo', $md);
        $this->assertStringContainsString('&lt;metric onmouseover=&quot;x&quot;&gt;\\|quality', $md);
        $this->assertStringContainsString('sample <code>s&lt;1&gt;</code>', $md);
        $this->assertStringContainsString('metric <code>m&lt;2&gt;</code>', $md);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;failure&quot;)&lt;/script&gt;', $md);
        $this->assertStringNotContainsString('<script', $md);
        $this->assertStringNotContainsString('<img', $md);
        $this->assertStringNotContainsString('<metric', $md);
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
