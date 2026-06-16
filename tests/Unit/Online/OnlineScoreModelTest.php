<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Online;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Padosoft\EvalHarness\Online\OnlineScore;
use Padosoft\EvalHarness\Tests\TestCase;

final class OnlineScoreModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_name(): void
    {
        $this->assertSame('eval_harness_online_scores', (new OnlineScore)->getTable());
    }

    public function test_casts_and_persistence(): void
    {
        OnlineScore::create([
            'dataset' => 'rag.faq',
            'sample_id' => 's1',
            'metric' => 'llm-as-judge',
            'score' => 0.9123,
            'passed' => true,
            'judge_model' => 'gpt-4o-mini',
            'details' => ['reason' => 'ok'],
            'judged_at' => Carbon::parse('2026-06-14 10:00:00'),
        ]);

        $row = OnlineScore::forDataset('rag.faq')->firstOrFail();

        $this->assertIsFloat($row->score);
        $this->assertEqualsWithDelta(0.9123, $row->score, 1e-9);
        $this->assertTrue($row->passed);
        $this->assertSame(['reason' => 'ok'], $row->details);
        $this->assertInstanceOf(Carbon::class, $row->judged_at);
    }

    public function test_for_dataset_scope_filters(): void
    {
        OnlineScore::create([
            'dataset' => 'a', 'sample_id' => 's', 'metric' => 'exact-match',
            'score' => 1.0, 'passed' => true, 'judged_at' => Carbon::now(),
        ]);
        OnlineScore::create([
            'dataset' => 'b', 'sample_id' => 's', 'metric' => 'exact-match',
            'score' => 0.0, 'passed' => false, 'judged_at' => Carbon::now(),
        ]);

        $this->assertSame(1, OnlineScore::forDataset('a')->count());
    }
}
