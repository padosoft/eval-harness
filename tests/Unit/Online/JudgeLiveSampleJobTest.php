<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Online;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\Metrics\MetricResolver;
use Padosoft\EvalHarness\Online\InteractionRetention;
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

    public function test_the_interaction_is_not_retained_by_default(): void
    {
        $row = $this->runJob('4', '4');

        $this->assertNull($row->input);
        $this->assertNull($row->expected_output);
        $this->assertFalse($row->isRetained());
    }

    /**
     * Redacted at the boundary, never written raw and cleaned up later:
     * "later" is where backups, replicas and log shippers already took a copy.
     */
    public function test_an_enabled_retention_stores_the_redacted_interaction(): void
    {
        config()->set('eval-harness.online.retention.enabled', true);
        config()->set('eval-harness.online.retention.redactor', MaskingRedactor::class);

        $row = $this->runJob('reply to ada@example.com', 'reply to ada@example.com');

        $this->assertTrue($row->isRetained());
        $this->assertSame('reply to [redacted]', $row->expected_output);
        $this->assertSame('reply to [redacted]', $row->actual_output);
        $this->assertSame(MaskingRedactor::class, $row->redactor);
        $this->assertNotNull($row->redacted_at);
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
            $this->app->make(InteractionRetention::class),
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
