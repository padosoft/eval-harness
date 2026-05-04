<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi;

use Illuminate\Support\Facades\Storage;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\ReportApi\ReportArtifactId;
use Padosoft\EvalHarness\Tests\TestCase;

final class ReportApiRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.api.enabled', true);
        $app['config']->set('eval-harness.api.prefix', 'eval-harness/api');
        $app['config']->set('eval-harness.api.middleware', []);
        $app['config']->set('eval-harness.reports.disk', 'eval-api');
        $app['config']->set('eval-harness.reports.path_prefix', 'eval-harness/reports');
    }

    public function test_lists_report_artifacts_from_configured_reports_prefix(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/report.json', '{"schema_version":"eval-harness.report.v1"}');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/report.md', '# Report');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/ignored.txt', 'ignore');
        Storage::disk('eval-api')->put('outside/report.json', '{}');

        $response = $this->getJson('/eval-harness/api/reports')
            ->assertOk()
            ->assertJsonPath('schema_version', ReportApiSchema::VERSION)
            ->assertJsonCount(2, 'data');

        $paths = array_column($response->json('data'), 'path');
        sort($paths);

        $this->assertSame(['rag/report.json', 'rag/report.md'], $paths);
        $this->assertSame(ReportArtifactId::encode('rag/report.json'), $response->json('data.0.id'));
        $this->assertSame('json', $response->json('data.0.format'));
        $this->assertNull($response->json('data.0.size_bytes'));
        $this->assertNull($response->json('data.0.last_modified'));
    }

    public function test_shows_json_report_artifact_by_url_safe_id(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/report.json', json_encode([
            'schema_version' => 'eval-harness.report.v1',
            'dataset' => 'rag.factuality',
            'cohorts' => [],
        ], JSON_THROW_ON_ERROR));

        $id = ReportArtifactId::encode('rag/report.json');

        $response = $this->getJson('/eval-harness/api/reports/'.$id)
            ->assertOk()
            ->assertJsonPath('schema_version', ReportApiSchema::VERSION)
            ->assertJsonPath('data.artifact.path', 'rag/report.json')
            ->assertJsonPath('data.artifact.format', 'json')
            ->assertJsonPath('data.report.dataset', 'rag.factuality')
            ->assertJsonPath('data.content', null);

        $this->assertIsInt($response->json('data.artifact.size_bytes'));
        $this->assertIsInt($response->json('data.artifact.last_modified'));
    }

    public function test_shows_markdown_report_artifact_by_url_safe_id(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/report.md', "# Eval report\n");

        $id = ReportArtifactId::encode('rag/report.md');

        $this->getJson('/eval-harness/api/reports/'.$id)
            ->assertOk()
            ->assertJsonPath('data.artifact.format', 'markdown')
            ->assertJsonPath('data.report', null)
            ->assertJsonPath('data.content', "# Eval report\n");
    }

    public function test_rejects_traversal_report_ids(): void
    {
        Storage::fake('eval-api');
        $id = rtrim(strtr(base64_encode('../secret.json'), '+/', '-_'), '=');

        $this->getJson('/eval-harness/api/reports/'.$id)->assertNotFound();
    }

    public function test_rejects_windows_drive_letter_report_paths(): void
    {
        $this->expectException(EvalRunException::class);

        ReportArtifactId::encode('C:/tmp/report.json');
    }

    public function test_malformed_json_report_returns_unprocessable_entity(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/bad.json', '{not json');

        $id = ReportArtifactId::encode('rag/bad.json');

        $this->getJson('/eval-harness/api/reports/'.$id)->assertUnprocessable();
    }

    public function test_shows_cohorts_from_a_json_report_artifact_by_url_safe_id(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/report.json', json_encode([
            'schema_version' => 'eval-harness.report.v1',
            'cohorts' => [
                [
                    'name' => 'geography',
                    'label' => 'geography',
                    'is_untagged' => false,
                    'sample_count' => 2,
                    'metrics' => [
                        'exact-match' => ['mean' => 0.5, 'p50' => 0.6, 'p95' => 0.7, 'pass_rate' => 1.0],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $id = ReportArtifactId::encode('rag/report.json');

        $response = $this->getJson('/eval-harness/api/reports/'.$id.'/cohorts')
            ->assertOk()
            ->assertJsonPath('schema_version', ReportApiSchema::VERSION)
            ->assertJsonPath('data.artifact.format', 'json');

        $cohorts = $response->json('data.cohorts');
        $this->assertIsArray($cohorts);
        $this->assertCount(1, $cohorts);
        $this->assertSame('geography', $cohorts[0]['name']);
    }

    public function test_shows_histograms_from_a_json_report_artifact_by_url_safe_id(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/report.json', json_encode([
            'schema_version' => 'eval-harness.report.v1',
            'metric_distributions' => [
                'exact-match' => [
                    ['min' => 0.0, 'max' => 0.5, 'count' => 1],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $id = ReportArtifactId::encode('rag/report.json');

        $this->getJson('/eval-harness/api/reports/'.$id.'/histograms')
            ->assertOk()
            ->assertJsonPath('schema_version', ReportApiSchema::VERSION)
            ->assertJsonPath('data.artifact.format', 'json')
            ->assertJsonPath('data.histograms.exact-match.0.count', 1);
    }

    public function test_downloads_report_artifact_content_with_original_filename(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/report.md', "# Eval report\n");

        $id = ReportArtifactId::encode('rag/report.md');

        $response = $this->get('/eval-harness/api/reports/'.$id.'/download');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/markdown; charset=utf-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="report.md"');
        $this->assertSame("# Eval report\n", $response->getContent());
    }

    public function test_exports_report_rows_csv(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/report.json', json_encode([
            'schema_version' => 'eval-harness.report.v1',
            'metrics' => ['exact-match' => ['mean' => 1]],
            'samples' => [
                [
                    'id' => 's1',
                    'tags' => ['easy'],
                    'scores' => [
                        'exact-match' => ['score' => 1.0, 'details' => ['pass' => true]],
                    ],
                ],
                [
                    'id' => 's2',
                    'tags' => ['hard'],
                    'scores' => [],
                ],
            ],
            'failures' => [
                ['sample_id' => 's2', 'metric' => 'exact-match', 'error' => 'timeout'],
            ],
        ], JSON_THROW_ON_ERROR));

        $id = ReportArtifactId::encode('rag/report.json');

        $response = $this->get('/eval-harness/api/reports/'.$id.'/rows.csv');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');
        $response->assertHeader('Content-Disposition', 'attachment; filename="report.json.csv"');

        $rows = array_map('str_getcsv', explode("\n", trim($response->getContent())));
        $this->assertSame(['sample_id', 'tags', 'metric', 'score', 'error', 'details'], $rows[0]);
        $this->assertSame(['s1', '["easy"]', 'exact-match', '1', '', '{"pass":true}'], $rows[1]);
        $this->assertSame(['s2', '["hard"]', 'exact-match', '', 'timeout', ''], $rows[2]);
    }

    public function test_cohort_or_histogram_view_rejects_markdown_artifacts(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/report.md', '# Eval report');

        $id = ReportArtifactId::encode('rag/report.md');

        $this->getJson('/eval-harness/api/reports/'.$id.'/cohorts')->assertUnprocessable();
        $this->getJson('/eval-harness/api/reports/'.$id.'/histograms')->assertUnprocessable();
        $this->get('/eval-harness/api/reports/'.$id.'/rows.csv')->assertUnprocessable();
    }

    public function test_show_returns_not_found_when_encoded_id_points_to_directory(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->makeDirectory('eval-harness/reports/rag/archive.json');

        $id = ReportArtifactId::encode('rag/archive.json');

        $this->getJson('/eval-harness/api/reports/'.$id)->assertNotFound();
    }
}
