<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Online;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Padosoft\EvalHarness\Online\Events\OnlinePassRateDropped;
use Padosoft\EvalHarness\Online\OnlineDriftAlert;
use Padosoft\EvalHarness\Online\OnlineScore;
use Padosoft\EvalHarness\Tests\TestCase;

final class OnlineDriftAlertTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('eval-harness.online.alert.threshold', 0.8);
        config()->set('eval-harness.online.alert.window', 50);
        config()->set('eval-harness.online.alert.min_samples', 5);
    }

    private function seedScores(int $passed, int $failed): void
    {
        $i = 0;
        for ($p = 0; $p < $passed; $p++) {
            $this->row(true, $i++);
        }
        for ($f = 0; $f < $failed; $f++) {
            $this->row(false, $i++);
        }
    }

    private function row(bool $passed, int $offset): void
    {
        OnlineScore::create([
            'dataset' => 'rag.faq',
            'sample_id' => 's'.$offset,
            'metric' => 'exact-match',
            'score' => $passed ? 1.0 : 0.0,
            'passed' => $passed,
            'judged_at' => Carbon::parse('2026-06-14 09:00:00')->addMinutes($offset),
        ]);
    }

    public function test_dispatches_event_when_pass_rate_below_threshold(): void
    {
        Event::fake();
        // 6 pass, 4 fail => 0.6 < 0.8 threshold, 10 >= min_samples 5.
        $this->seedScores(6, 4);

        $fired = $this->app->make(OnlineDriftAlert::class)->evaluate('rag.faq');

        $this->assertTrue($fired);
        Event::assertDispatched(
            OnlinePassRateDropped::class,
            static fn (OnlinePassRateDropped $e): bool => $e->dataset === 'rag.faq' && abs($e->passRate - 0.6) < 1e-9 && $e->sampleCount === 10,
        );
    }

    public function test_no_event_when_above_threshold(): void
    {
        Event::fake();
        $this->seedScores(9, 1); // 0.9 >= 0.8

        $fired = $this->app->make(OnlineDriftAlert::class)->evaluate('rag.faq');

        $this->assertFalse($fired);
        Event::assertNotDispatched(OnlinePassRateDropped::class);
    }

    public function test_no_event_below_min_samples(): void
    {
        Event::fake();
        $this->seedScores(0, 3); // 0% pass but only 3 < min_samples 5

        $fired = $this->app->make(OnlineDriftAlert::class)->evaluate('rag.faq');

        $this->assertFalse($fired);
        Event::assertNotDispatched(OnlinePassRateDropped::class);
    }
}
