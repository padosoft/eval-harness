<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi\Diff;

use Illuminate\Support\Facades\Storage;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\ReportApi\ReportArtifactId;
use Padosoft\EvalHarness\Reports\ReportSchema;
use Padosoft\EvalHarness\Tests\TestCase;

final class ReportDiffRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.api.enabled', true);
        $app['config']->set('eval-harness.api.prefix', 'eval-harness/api');
        $app['config']->set('eval-harness.api.middleware', []);
        $app['config']->set('eval-harness.reports.disk', 'eval-api');
        $app['config']->set('eval-harness.reports.path_prefix', 'eval-harness/reports');
    }

    public function test_diff_returns_envelope_with_per_endpoint_schema_discriminator(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put(
            'eval-harness/reports/rag/run-a.json',
            json_encode($this->reportFixture(macroF1: 0.85), JSON_THROW_ON_ERROR),
        );
        Storage::disk('eval-api')->put(
            'eval-harness/reports/rag/run-b.json',
            json_encode($this->reportFixture(macroF1: 0.78), JSON_THROW_ON_ERROR),
        );

        $idA = ReportArtifactId::encode('rag/run-a.json');
        $idB = ReportArtifactId::encode('rag/run-b.json');

        $response = $this->getJson('/eval-harness/api/reports/'.$idA.'/diff/'.$idB)
            ->assertOk()
            ->assertJsonPath('schema_version', ReportApiSchema::VERSION)
            ->assertJsonPath('schema', ReportApiSchema::SCHEMA_DIFF)
            ->assertJsonPath('data.left.artifact.path', 'rag/run-a.json')
            ->assertJsonPath('data.right.artifact.path', 'rag/run-b.json');

        $this->assertEqualsWithDelta(-0.07, $response->json('data.delta.macro_f1'), 0.0001);
    }

    public function test_traversal_id_returns_not_found(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put(
            'eval-harness/reports/rag/run-a.json',
            json_encode($this->reportFixture(), JSON_THROW_ON_ERROR),
        );

        $traversal = rtrim(strtr(base64_encode('../secret.json'), '+/', '-_'), '=');
        $valid = ReportArtifactId::encode('rag/run-a.json');

        $this->getJson('/eval-harness/api/reports/'.$traversal.'/diff/'.$valid)->assertNotFound();
        $this->getJson('/eval-harness/api/reports/'.$valid.'/diff/'.$traversal)->assertNotFound();
    }

    public function test_markdown_artifact_rejected_with_unprocessable_entity(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put(
            'eval-harness/reports/rag/run-a.md',
            "# Report\n",
        );
        Storage::disk('eval-api')->put(
            'eval-harness/reports/rag/run-b.json',
            json_encode($this->reportFixture(), JSON_THROW_ON_ERROR),
        );

        $idA = ReportArtifactId::encode('rag/run-a.md');
        $idB = ReportArtifactId::encode('rag/run-b.json');

        $this->getJson('/eval-harness/api/reports/'.$idA.'/diff/'.$idB)->assertStatus(422);
    }

    public function test_schema_version_mismatch_returns_unprocessable_entity(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put(
            'eval-harness/reports/rag/run-a.json',
            json_encode($this->reportFixture(), JSON_THROW_ON_ERROR),
        );
        Storage::disk('eval-api')->put(
            'eval-harness/reports/rag/run-b.json',
            json_encode(
                array_merge($this->reportFixture(), ['schema_version' => 'eval-harness.report.v0']),
                JSON_THROW_ON_ERROR,
            ),
        );

        $idA = ReportArtifactId::encode('rag/run-a.json');
        $idB = ReportArtifactId::encode('rag/run-b.json');

        $this->getJson('/eval-harness/api/reports/'.$idA.'/diff/'.$idB)->assertStatus(422);
    }

    public function test_unknown_artifact_id_returns_not_found(): void
    {
        Storage::fake('eval-api');

        $missing = ReportArtifactId::encode('rag/missing.json');
        $alsoMissing = ReportArtifactId::encode('rag/also-missing.json');

        $this->getJson('/eval-harness/api/reports/'.$missing.'/diff/'.$alsoMissing)->assertNotFound();
    }

    /**
     * @return array<string, mixed>
     */
    private function reportFixture(float $macroF1 = 0.8): array
    {
        return [
            'schema_version' => ReportSchema::VERSION,
            'dataset_schema_version' => 'eval-harness.dataset.v1',
            'dataset' => 'rag.factuality',
            'started_at' => 1730000000.0,
            'finished_at' => 1730000060.0,
            'duration_seconds' => 60.0,
            'total_samples' => 100,
            'total_failures' => 5,
            'metrics' => [
                'exact-match' => ['mean' => 0.7, 'p50' => 1.0, 'p95' => 1.0, 'pass_rate' => 0.7],
            ],
            'metric_distributions' => ['exact-match' => []],
            'usage' => [
                'observations' => 0,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'cost_usd' => 0.0,
                'reported' => [
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'total_tokens' => 0,
                    'cost_usd' => 0,
                    'latency_ms' => 0,
                ],
                'latency_ms' => ['count' => 0, 'total' => 0.0, 'mean' => 0.0, 'max' => 0.0],
            ],
            'cohorts' => [],
            'adversarial' => null,
            'macro_f1' => $macroF1,
            'samples' => [],
            'failures' => [],
        ];
    }
}
