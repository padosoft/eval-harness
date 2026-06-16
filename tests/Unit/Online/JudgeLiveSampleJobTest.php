<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Online;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Online\JudgeLiveSampleJob;
use Padosoft\EvalHarness\Online\OnlineDriftAlert;
use Padosoft\EvalHarness\Online\OnlineScore;
use Padosoft\EvalHarness\Tests\TestCase;

final class JudgeLiveSampleJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Use the offline exact-match metric so no provider is touched.
        Http::fake();
        config()->set('eval-harness.online.metric', 'exact-match');
        config()->set('eval-harness.online.pass_threshold', 0.7);
    }

    private function runJob(string $expected, string $actual): OnlineScore
    {
        $job = new JudgeLiveSampleJob(
            dataset: 'rag.faq',
            sampleId: 's1',
            input: ['question' => 'q'],
            expected: $expected,
            actual: $actual,
        );

        $job->handle(
            $this->app->make(MetricResolver::class),
            $this->app->make(ConfigRepository::class),
            $this->app->make(OnlineDriftAlert::class),
        );

        return OnlineScore::forDataset('rag.faq')->firstOrFail();
    }

    public function test_persists_passing_score_for_exact_match(): void
    {
        $row = $this->runJob('Paris', 'Paris');

        $this->assertSame(1.0, $row->score);
        $this->assertTrue($row->passed);
        $this->assertSame('exact-match', $row->metric);
        $this->assertSame('s1', $row->sample_id);
    }

    public function test_persists_failing_score_for_mismatch(): void
    {
        $row = $this->runJob('Paris', 'Berlin');

        $this->assertSame(0.0, $row->score);
        $this->assertFalse($row->passed);
    }
}
