<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi\Adversarial;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifest;
use Padosoft\EvalHarness\ReportApi\Adversarial\ManifestResourceFactory;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\Tests\TestCase;

final class ManifestRouteTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.api.enabled', true);
        $app['config']->set('eval-harness.api.prefix', 'eval-harness/api');
        $app['config']->set('eval-harness.api.middleware', []);
        $app['config']->set('eval-harness.adversarial.manifests.disk', 'eval-api');
        $app['config']->set('eval-harness.adversarial.manifests.path_prefix', 'eval-harness/adversarial/manifests');
    }

    public function test_index_returns_summaries_with_per_endpoint_discriminator(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put(
            'eval-harness/adversarial/manifests/rag-safety.json',
            json_encode($this->manifestPayload('rag-safety', macroF1: 0.84), JSON_THROW_ON_ERROR),
        );
        Storage::disk('eval-api')->put(
            'eval-harness/adversarial/manifests/agents-safety.json',
            json_encode($this->manifestPayload('agents-safety', macroF1: 0.76), JSON_THROW_ON_ERROR),
        );
        Storage::disk('eval-api')->put('eval-harness/adversarial/manifests/ignored.txt', 'noise');
        Storage::disk('eval-api')->put('outside/manifest.json', '{}');

        $response = $this->getJson('/eval-harness/api/adversarial/manifests')
            ->assertOk()
            ->assertJsonPath('schema_version', ReportApiSchema::VERSION)
            ->assertJsonPath('schema', ReportApiSchema::SCHEMA_ADVERSARIAL_MANIFESTS)
            ->assertJsonCount(2, 'data');

        $names = array_column($response->json('data'), 'name');
        sort($names);

        $this->assertSame(['agents-safety', 'rag-safety'], $names);
        $this->assertEqualsWithDelta(0.76, $response->json('data.0.latest_macro_f1'), 0.0001);
    }

    public function test_show_returns_full_manifest_with_runs(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put(
            'eval-harness/adversarial/manifests/rag-safety.json',
            json_encode($this->manifestPayload('rag-safety', macroF1: 0.84), JSON_THROW_ON_ERROR),
        );

        $this->getJson('/eval-harness/api/adversarial/manifests/rag-safety')
            ->assertOk()
            ->assertJsonPath('schema_version', ReportApiSchema::VERSION)
            ->assertJsonPath('schema', ReportApiSchema::SCHEMA_ADVERSARIAL_MANIFEST)
            ->assertJsonPath('data.name', 'rag-safety')
            ->assertJsonPath('data.runs_count', 1)
            ->assertJsonPath('data.runs.0.macro_f1', 0.84);
    }

    public function test_unknown_manifest_returns_not_found(): void
    {
        Storage::fake('eval-api');

        $this->getJson('/eval-harness/api/adversarial/manifests/missing')->assertNotFound();
    }

    public function test_show_returns_unprocessable_entity_for_malformed_manifest_payload(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put(
            'eval-harness/adversarial/manifests/broken.json',
            '{"schema_version":"not-a-valid-manifest"}',
        );

        $this->getJson('/eval-harness/api/adversarial/manifests/broken')
            ->assertStatus(422);
    }

    public function test_traversal_in_manifest_name_is_rejected_by_route_constraint(): void
    {
        Storage::fake('eval-api');
        $this->getJson('/eval-harness/api/adversarial/manifests/'.urlencode('../secret'))
            ->assertNotFound();
    }

    public function test_index_returns_404_with_discovery_not_configured_when_disk_missing(): void
    {
        config()->set('eval-harness.adversarial.manifests.disk', null);

        $this->getJson('/eval-harness/api/adversarial/manifests')
            ->assertNotFound()
            ->assertJsonPath('error', 'discovery_not_configured');
    }

    public function test_show_returns_404_with_discovery_not_configured_when_disk_missing(): void
    {
        config()->set('eval-harness.adversarial.manifests.disk', null);

        $this->getJson('/eval-harness/api/adversarial/manifests/rag-safety')
            ->assertNotFound()
            ->assertJsonPath('error', 'discovery_not_configured');
    }

    public function test_invalid_manifest_disk_returns_service_unavailable(): void
    {
        config()->set('eval-harness.adversarial.manifests.disk', 'missing-disk');

        $this->getJson('/eval-harness/api/adversarial/manifests')
            ->assertStatus(503);
    }

    public function test_show_route_passes_injected_request_through_to_resource(): void
    {
        // Regression for Copilot review on PR #41 (commit 84642fd).
        // The old controller built the manifest payload via
        // `(new ManifestResource($manifest))->toArray(request())`,
        // ignoring the injected `$request`. Pin the corrected
        // pass-through behaviour by replacing the resource factory
        // and asserting it sees the route request. A plain HTTP header
        // assertion is insufficient because request() resolves to the
        // same object during normal HTTP dispatch.
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put(
            'eval-harness/adversarial/manifests/rag-safety.json',
            json_encode($this->manifestPayload('rag-safety'), JSON_THROW_ON_ERROR),
        );

        $seenRequest = null;
        $this->app->bind(ManifestResourceFactory::class, static function () use (&$seenRequest): ManifestResourceFactory {
            return new class($seenRequest) extends ManifestResourceFactory
            {
                private mixed $seenRequest;

                public function __construct(mixed &$seenRequest)
                {
                    $this->seenRequest = &$seenRequest;
                }

                public function toArray(AdversarialRunManifest $manifest, Request $request): array
                {
                    $this->seenRequest = $request;

                    return parent::toArray($manifest, $request);
                }
            };
        });

        $this->getJson(
            '/eval-harness/api/adversarial/manifests/rag-safety',
            ['Accept-Language' => 'en-US'],
        )->assertOk()->assertJsonPath('data.name', 'rag-safety');

        $this->assertInstanceOf(Request::class, $seenRequest);
        $this->assertSame('en-US', $seenRequest->headers->get('Accept-Language'));
    }

    public function test_index_skips_malformed_manifest_files(): void
    {
        Storage::fake('eval-api');
        Storage::disk('eval-api')->put('eval-harness/adversarial/manifests/good.json', json_encode($this->manifestPayload('good'), JSON_THROW_ON_ERROR));
        Storage::disk('eval-api')->put('eval-harness/adversarial/manifests/broken.json', '{"not":"a manifest"}');

        $response = $this->getJson('/eval-harness/api/adversarial/manifests')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->assertSame('good', $response->json('data.0.name'));
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestPayload(string $name, float $macroF1 = 0.8): array
    {
        return [
            'schema_version' => AdversarialRunManifest::SCHEMA_VERSION,
            'manifest' => $name,
            'updated_at' => 1730001500.0,
            'runs' => [
                [
                    'run_id' => 'run-'.$name.'-001',
                    'dataset' => 'rag.factuality',
                    'report_schema_version' => 'eval-harness.report.v1',
                    'started_at' => 1730000000.0,
                    'finished_at' => 1730001500.0,
                    'duration_seconds' => 1500.0,
                    'total_samples' => 100,
                    'total_failures' => 5,
                    'macro_f1' => $macroF1,
                    'metrics' => [
                        'refusal-quality' => [
                            'mean' => $macroF1,
                            'p50' => $macroF1,
                            'p95' => 1.0,
                            'pass_rate' => $macroF1,
                        ],
                    ],
                    'adversarial' => [
                        'total_samples' => 100,
                        'categories' => [
                            [
                                'category' => 'prompt-injection',
                                'label' => 'Prompt injection',
                                'severity' => 'high',
                                'sample_count' => 100,
                                'compliance_frameworks' => ['OWASP LLM'],
                                'metrics' => [
                                    'refusal-quality' => [
                                        'mean' => $macroF1,
                                        'p50' => $macroF1,
                                        'p95' => 1.0,
                                        'pass_rate' => $macroF1,
                                    ],
                                ],
                            ],
                        ],
                        'compliance_frameworks' => [
                            ['framework' => 'OWASP LLM', 'sample_count' => 100, 'categories' => ['prompt-injection']],
                        ],
                    ],
                ],
            ],
        ];
    }
}
