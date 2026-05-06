<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi\Trend;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\ReportApi\ReportArtifactRepository;
use Padosoft\EvalHarness\ReportApi\Trend\DatasetTrendRepository;
use Padosoft\EvalHarness\Tests\TestCase;
use RuntimeException;

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

    public function test_dataset_trend_discovers_reports_saved_under_arbitrary_prefix_paths(): void
    {
        Storage::fake('eval-api');
        $this->putReportAt('eval-harness/reports/evals/ci-rag.json', 'rag', 20.0, 0.8);
        $this->putReportAt('eval-harness/reports/eval-report.json', 'rag', 10.0, 0.7);
        $this->putReportAt('eval-harness/reports/evals/other.json', 'other', 30.0, 0.9);

        $this->getJson('/eval-harness/api/datasets/rag/trend')
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.points.0.path', 'eval-report.json')
            ->assertJsonPath('data.points.1.path', 'evals/ci-rag.json');
    }

    public function test_missing_dataset_returns_empty_points_when_prefix_has_other_reports(): void
    {
        Storage::fake('eval-api');
        $this->putReportAt('eval-harness/reports/evals/other.json', 'other', 30.0, 0.9);

        $this->getJson('/eval-harness/api/datasets/rag/trend')
            ->assertOk()
            ->assertJsonPath('data.dataset', 'rag')
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.points', []);
    }

    public function test_missing_report_prefix_returns_empty_points(): void
    {
        Storage::fake('eval-api');

        $this->getJson('/eval-harness/api/datasets/rag/trend')
            ->assertOk()
            ->assertJsonPath('data.dataset', 'rag')
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.points', []);
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

    public function test_path_traversal_dataset_name_returns_not_found(): void
    {
        Storage::fake('eval-api');

        $this->getJson('/eval-harness/api/datasets/%2E%2E/trend')
            ->assertNotFound();
    }

    public function test_single_dot_dataset_name_returns_not_found(): void
    {
        Storage::fake('eval-api');

        $this->getJson('/eval-harness/api/datasets/./trend')
            ->assertNotFound();
    }

    public function test_double_dot_inside_dataset_segment_is_allowed(): void
    {
        Storage::fake('eval-api');

        $this->getJson('/eval-harness/api/datasets/foo..bar/trend')
            ->assertOk()
            ->assertJsonPath('data.dataset', 'foo..bar')
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

    public function test_trend_read_failures_return_service_unavailable(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('allFiles')
            ->once()
            ->with('eval-harness/reports')
            ->andReturn(['eval-harness/reports/rag/broken.json']);
        $disk->shouldReceive('get')
            ->once()
            ->with('eval-harness/reports/rag/broken.json')
            ->andThrow(new RuntimeException('storage unavailable'));

        $factory = Mockery::mock(FilesystemFactory::class);
        $factory->shouldReceive('disk')
            ->once()
            ->with('eval-api')
            ->andReturn($disk);

        $this->app->instance(FilesystemFactory::class, $factory);
        $this->app->forgetInstance(ReportArtifactRepository::class);
        $this->app->forgetInstance(DatasetTrendRepository::class);

        $this->getJson('/eval-harness/api/datasets/rag/trend')
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'Dataset trend could not be read.');
    }

    public function test_limit_ties_are_deterministic_by_path(): void
    {
        Storage::fake('eval-api');
        $this->putReport('rag', 'z.json', 10.0, 0.9);
        $this->putReport('rag', 'a.json', 10.0, 0.7);
        $this->putReport('rag', 'm.json', 10.0, 0.8);

        $this->getJson('/eval-harness/api/datasets/rag/trend?limit=2')
            ->assertOk()
            ->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.points.0.path', 'rag/m.json')
            ->assertJsonPath('data.points.1.path', 'rag/z.json');
    }

    private function putReport(string $dataset, string $filename, float $startedAt, float $macroF1): void
    {
        $this->putReportAt('eval-harness/reports/'.$dataset.'/'.$filename, $dataset, $startedAt, $macroF1);
    }

    private function putReportAt(string $path, string $dataset, float $startedAt, float $macroF1): void
    {
        Storage::disk('eval-api')->put($path, json_encode([
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
