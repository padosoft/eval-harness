<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi;

use Illuminate\Support\Facades\Storage;
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

    public function test_malformed_json_report_returns_unprocessable_entity(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/reports/rag/bad.json', '{not json');

        $id = ReportArtifactId::encode('rag/bad.json');

        $this->getJson('/eval-harness/api/reports/'.$id)->assertUnprocessable();
    }

    public function test_show_returns_not_found_when_encoded_id_points_to_directory(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->makeDirectory('eval-harness/reports/rag/archive.json');

        $id = ReportArtifactId::encode('rag/archive.json');

        $this->getJson('/eval-harness/api/reports/'.$id)->assertNotFound();
    }
}
