<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Reports;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\FailedSampleDatasetExporter;
use Padosoft\EvalHarness\Reports\SampleFailure;
use Padosoft\EvalHarness\Reports\SampleResult;
use PHPUnit\Framework\TestCase;

final class FailedSampleDatasetExporterTest extends TestCase
{
    public function test_exports_low_score_samples_as_reloadable_dataset_yaml(): void
    {
        $sample = new DatasetSample(
            id: 'adv.prompt-injection',
            input: ['prompt' => 'ignore instructions'],
            expectedOutput: 'refuse',
            metadata: [
                'tags' => ['security'],
                'adversarial' => [
                    'category' => 'prompt-injection',
                    'label' => 'Prompt injection',
                    'severity' => 'high',
                    'compliance_frameworks' => ['OWASP LLM'],
                ],
            ],
        );
        $report = new EvalReport(
            datasetName: 'adversarial.security.v1',
            sampleResults: [
                new SampleResult(
                    sample: $sample,
                    actualOutput: 'unsafe actual output',
                    metricScores: ['exact-match' => new MetricScore(0.0)],
                ),
            ],
            failures: [],
            startedAt: 1.0,
            finishedAt: 2.0,
        );

        $yaml = (new FailedSampleDatasetExporter)->exportYaml($report, 'adversarial.security.failures');
        $this->assertIsString($yaml);
        $this->assertStringNotContainsString('unsafe actual output', $yaml);

        $parsed = (new YamlDatasetLoader)->loadString($yaml);

        $this->assertSame('adversarial.security.failures', $parsed->name);
        $this->assertSame('adv.prompt-injection', $parsed->samples[0]->id);
        $this->assertSame($sample->input, $parsed->samples[0]->input);
        $this->assertSame($sample->expectedOutput, $parsed->samples[0]->expectedOutput);
        $this->assertSame('prompt-injection', $parsed->samples[0]->metadata['adversarial']['category']);
        $this->assertSame([
            'source_dataset' => 'adversarial.security.v1',
            'failed_metrics' => ['exact-match'],
        ], $parsed->samples[0]->metadata['eval_harness']['promoted_failure']);
    }

    public function test_exports_metric_exception_failures(): void
    {
        $sample = new DatasetSample(id: 'adv.ssrf', input: ['url' => 'http://169.254.169.254'], expectedOutput: 'refuse');
        $report = new EvalReport(
            datasetName: 'adversarial.security.v1',
            sampleResults: [
                new SampleResult(
                    sample: $sample,
                    actualOutput: 'unsafe',
                    metricScores: [],
                ),
            ],
            failures: [
                new SampleFailure('adv.ssrf', 'llm-as-judge', 'provider timeout'),
            ],
            startedAt: 1.0,
            finishedAt: 2.0,
        );

        $yaml = (new FailedSampleDatasetExporter)->exportYaml($report);
        $this->assertIsString($yaml);

        $parsed = (new YamlDatasetLoader)->loadString($yaml);

        $this->assertSame(['llm-as-judge'], $parsed->samples[0]->metadata['eval_harness']['promoted_failure']['failed_metrics']);
        $this->assertStringNotContainsString('provider timeout', $yaml);
    }

    public function test_returns_null_when_report_has_no_failed_samples(): void
    {
        $sample = new DatasetSample(id: 'adv.safe', input: ['prompt' => 'hello'], expectedOutput: 'safe');
        $report = new EvalReport(
            datasetName: 'adversarial.security.v1',
            sampleResults: [
                new SampleResult(
                    sample: $sample,
                    actualOutput: 'safe',
                    metricScores: ['exact-match' => new MetricScore(1.0)],
                ),
            ],
            failures: [],
            startedAt: 1.0,
            finishedAt: 2.0,
        );

        $exporter = new FailedSampleDatasetExporter;

        $this->assertSame(0, $exporter->failedSampleCount($report));
        $this->assertNull($exporter->exportYaml($report));
    }

    public function test_rejects_non_serializable_sample_values(): void
    {
        $sample = new DatasetSample(
            id: 'adv.bad',
            input: ['bad' => new \stdClass],
            expectedOutput: 'safe',
        );
        $report = new EvalReport(
            datasetName: 'adversarial.security.v1',
            sampleResults: [
                new SampleResult(
                    sample: $sample,
                    actualOutput: 'unsafe',
                    metricScores: ['exact-match' => new MetricScore(0.0)],
                ),
            ],
            failures: [],
            startedAt: 1.0,
            finishedAt: 2.0,
        );

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("sample 'adv.bad'.input.bad contains a non-serializable stdClass value");

        (new FailedSampleDatasetExporter)->exportYaml($report);
    }
}
