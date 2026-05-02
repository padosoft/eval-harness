<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Reports;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\DatasetSchema;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\JsonReportRenderer;
use Padosoft\EvalHarness\Reports\ReportSchema;
use Padosoft\EvalHarness\Reports\SampleFailure;
use Padosoft\EvalHarness\Reports\SampleResult;
use PHPUnit\Framework\TestCase;

final class JsonReportRendererTest extends TestCase
{
    public function test_top_level_keys_are_stable(): void
    {
        $report = new EvalReport('x', [], [], 0.0, 0.0);
        $json = (new JsonReportRenderer)->render($report);

        $expectedKeys = [
            'schema_version',
            'dataset_schema_version',
            'dataset',
            'started_at',
            'finished_at',
            'duration_seconds',
            'total_samples',
            'total_failures',
            'metrics',
            'macro_f1',
            'samples',
            'failures',
        ];
        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json, "Missing top-level key '$key'.");
        }

        $this->assertSame(ReportSchema::VERSION, $json['schema_version']);
        $this->assertSame(DatasetSchema::VERSION, $json['dataset_schema_version']);
    }

    public function test_metrics_aggregate_shape(): void
    {
        $report = new EvalReport(
            datasetName: 'x',
            sampleResults: [
                new SampleResult(
                    sample: new DatasetSample(id: 's1', input: [], expectedOutput: 'e'),
                    actualOutput: 'e',
                    metricScores: ['exact-match' => new MetricScore(1.0, ['match' => true])],
                ),
            ],
            failures: [],
            startedAt: 0.0,
            finishedAt: 0.0,
        );

        $json = (new JsonReportRenderer)->render($report);

        $this->assertArrayHasKey('exact-match', $json['metrics']);
        $this->assertSame(1.0, $json['metrics']['exact-match']['mean']);
        $this->assertSame(1.0, $json['metrics']['exact-match']['p50']);
        $this->assertSame(1.0, $json['metrics']['exact-match']['p95']);
        $this->assertSame(1.0, $json['metrics']['exact-match']['pass_rate']);

        $this->assertCount(1, $json['samples']);
        $this->assertSame('s1', $json['samples'][0]['id']);
        $this->assertSame(1.0, $json['samples'][0]['scores']['exact-match']['score']);
        $this->assertSame(['match' => true], $json['samples'][0]['scores']['exact-match']['details']);
    }

    public function test_failures_are_serialised(): void
    {
        $report = new EvalReport(
            datasetName: 'x',
            sampleResults: [],
            failures: [new SampleFailure('s1', 'llm-as-judge', 'boom')],
            startedAt: 0.0,
            finishedAt: 0.0,
        );

        $json = (new JsonReportRenderer)->render($report);

        $this->assertSame(1, $json['total_failures']);
        $this->assertSame([['sample_id' => 's1', 'metric' => 'llm-as-judge', 'error' => 'boom']], $json['failures']);
    }

    public function test_json_encodable(): void
    {
        $report = new EvalReport('x', [], [], 0.0, 0.0);
        $json = (new JsonReportRenderer)->render($report);
        $encoded = json_encode($json, JSON_THROW_ON_ERROR);
        $this->assertNotFalse($encoded);
        $this->assertJson($encoded);
    }
}
