<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi\Online;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Padosoft\EvalHarness\Online\OnlineScore;
use Padosoft\EvalHarness\ReportApi\ReportApiSchema;
use Padosoft\EvalHarness\Tests\TestCase;

final class OnlineTrendControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.api.enabled', true);
        $app['config']->set('eval-harness.api.prefix', 'eval-harness/api');
        $app['config']->set('eval-harness.api.middleware', []);
        $app['config']->set('eval-harness.online.alert.threshold', 0.8);
    }

    private function score(string $dataset, string $date, bool $passed): void
    {
        OnlineScore::create([
            'dataset' => $dataset,
            'sample_id' => 's',
            'metric' => 'exact-match',
            'score' => $passed ? 1.0 : 0.0,
            'passed' => $passed,
            'judged_at' => Carbon::parse($date),
        ]);
    }

    public function test_returns_envelope_with_points_and_threshold(): void
    {
        $this->score('rag.faq', '2026-06-14 09:00:00', true);
        $this->score('rag.faq', '2026-06-14 10:00:00', false);

        $this->getJson(route('eval-harness.api.online.trend', ['dataset' => 'rag.faq']))
            ->assertOk()
            ->assertJsonPath('schema_version', ReportApiSchema::VERSION)
            ->assertJsonPath('schema', ReportApiSchema::SCHEMA_ONLINE_TREND)
            ->assertJsonPath('data.dataset', 'rag.faq')
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.threshold', 0.8)
            ->assertJsonPath('data.points.0.date', '2026-06-14')
            ->assertJsonPath('data.points.0.pass_rate', 0.5)
            ->assertJsonPath('data.points.0.total', 2)
            ->assertJsonPath('data.points.0.passed', 1);
    }

    public function test_unknown_dataset_returns_empty_points(): void
    {
        $this->getJson(route('eval-harness.api.online.trend', ['dataset' => 'missing']))
            ->assertOk()
            ->assertJsonPath('data.dataset', 'missing')
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.points', []);
    }

    public function test_path_traversal_dataset_returns_not_found(): void
    {
        $this->getJson('/eval-harness/api/online/%2E%2E/trend')
            ->assertNotFound();
    }
}
