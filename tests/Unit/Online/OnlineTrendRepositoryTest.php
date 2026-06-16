<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Online;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Padosoft\EvalHarness\Online\OnlineScore;
use Padosoft\EvalHarness\Online\OnlineTrendRepository;
use Padosoft\EvalHarness\Tests\TestCase;

final class OnlineTrendRepositoryTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_groups_pass_rate_by_day_ascending(): void
    {
        // 2026-06-12: 2/2 pass; 06-13: 1/2; 06-14: 0/1
        $this->score('rag.faq', '2026-06-12 09:00:00', true);
        $this->score('rag.faq', '2026-06-12 10:00:00', true);
        $this->score('rag.faq', '2026-06-13 09:00:00', true);
        $this->score('rag.faq', '2026-06-13 10:00:00', false);
        $this->score('rag.faq', '2026-06-14 09:00:00', false);
        // Different dataset must not leak in.
        $this->score('other', '2026-06-14 09:00:00', true);

        $points = (new OnlineTrendRepository)->trend('rag.faq', 30);

        $this->assertCount(3, $points);
        $this->assertSame('2026-06-12', $points[0]['date']);
        $this->assertEqualsWithDelta(1.0, $points[0]['pass_rate'], 1e-9);
        $this->assertSame(2, $points[0]['total']);
        $this->assertSame('2026-06-13', $points[1]['date']);
        $this->assertEqualsWithDelta(0.5, $points[1]['pass_rate'], 1e-9);
        $this->assertSame('2026-06-14', $points[2]['date']);
        $this->assertEqualsWithDelta(0.0, $points[2]['pass_rate'], 1e-9);
    }

    public function test_limit_keeps_newest_days(): void
    {
        $this->score('rag.faq', '2026-06-12 09:00:00', true);
        $this->score('rag.faq', '2026-06-13 09:00:00', true);
        $this->score('rag.faq', '2026-06-14 09:00:00', false);

        $points = (new OnlineTrendRepository)->trend('rag.faq', 2);

        $this->assertCount(2, $points);
        $this->assertSame('2026-06-13', $points[0]['date']);
        $this->assertSame('2026-06-14', $points[1]['date']);
    }
}
