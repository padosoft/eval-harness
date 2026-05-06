<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi\Trend;

use Illuminate\Support\Facades\Storage;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\Tests\TestCase;

final class DatasetTrendRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.api.enabled', true);
        $app['config']->set('eval-harness.api.prefix', 'eval-harness/api');
        $app['config']->set('eval-harness.api.middleware', []);
        $app['config']->set('eval-harness.reports.disk', 'eval-api');
        $app['config']->set('eval-harness.reports.path_prefix', 'eval-harness/reports');
    }

    public function test_dataset_trend_returns_chronological_points(): void
    {
        Storage::fake('eval-api');
        $this->putReport('rag', 'new.json', 30.0, 0.9);
        $this->putReport('rag', 'old.json', 10.0, 0.7);

        $this->getJson('/eval-harness/api/datasets/rag/trend')
            ->assertOk()
            ->assertJsonPath('schema_version', ReportApiSchema::VERSION)
            ->assertJsonPath('schema', ReportApiSchema::SCHEMA_TREND)
            ->assertJsonPath('data.dataset', 'rag')
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.points.0.started_at', 10)
            ->assertJsonPath('data.points.1.started_at', 30)
            ->assertJsonPath('data.points.1.usage.total_tokens', 14)
            ->assertJsonPath('data.points.1.cohorts.0.label', 'all');
    }

    public function test_dataset_trend_caps_limit_to_one_hundred(): void
    {
        Storage::fake('eval-api');
        for ($i = 1; $i <= 105; $i++) {
            $this->putReport('rag', sprintf('run-%03d.json', $i), (float) $i, 0.5);
        }

        $this->getJson('/eval-harness/api/datasets/rag/trend?limit=500')
            ->assertOk()
            ->assertJsonPath('data.limit', 100)
            ->assertJsonPath('data.count', 100)
            ->assertJsonPath('data.points.0.started_at', 6);
    }

    public function test_empty_dataset_returns_empty_points(): void
    {
        Storage::fake('eval-api');

        $this->getJson('/eval-harness/api/datasets/empty/trend')
            ->assertOk()
            ->assertJsonPath('data.dataset', 'empty')
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.points', []);
    }

    public function test_malformed_reports_are_skipped(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/bad.json', '{not json');
        $this->putReport('rag', 'good.json', 20.0, 0.8);

        $this->getJson('/eval-harness/api/datasets/rag/trend')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.points.0.macro_f1', 0.8);
    }

    public function test_unsupported_report_schema_is_skipped(): void
    {
        Storage::fake('eval-api');
        $this->putReport('rag', 'good.json', 20.0, 0.8);
        Storage::disk('eval-api')->put('eval-harness/reports/rag/stale.json', json_encode([
            'schema_version' => 'eval-harness.report.v0',
            'dataset' => 'rag',
            'started_at' => 10.0,
            'macro_f1' => 0.1,
        ], JSON_THROW_ON_ERROR));

        $this->getJson('/eval-harness/api/datasets/rag/trend')
            ->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.points.0.path', 'rag/good.json');
    }

    private function putReport(string $dataset, string $filename, float $startedAt, float $macroF1): void
    {
        Storage::disk('eval-api')->put('eval-harness/reports/'.$dataset.'/'.$filename, json_encode([
            'schema_version' => 'eval-harness.report.v1',
            'dataset' => $dataset,
            'started_at' => $startedAt,
            'finished_at' => $startedAt + 1.0,
            'total_samples' => 2,
            'total_failures' => 0,
            'metrics' => [
                'exact-match' => ['mean' => $macroF1, 'p50' => $macroF1, 'p95' => $macroF1, 'pass_rate' => $macroF1],
            ],
            'usage' => [
                'total_tokens' => 14,
                'latency_ms' => ['count' => 1, 'mean' => 25.0],
            ],
            'cohorts' => [
                ['name' => 'all', 'label' => 'all', 'is_untagged' => false, 'sample_count' => 2, 'metrics' => []],
            ],
            'macro_f1' => $macroF1,
        ], JSON_THROW_ON_ERROR));
    }
}
