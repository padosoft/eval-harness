<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\MetricScore;
use PHPUnit\Framework\TestCase;

final class MetricScoreTest extends TestCase
{
    public function test_zero_is_accepted(): void
    {
        $score = new MetricScore(0.0);
        $this->assertSame(0.0, $score->score);
    }

    public function test_one_is_accepted(): void
    {
        $score = new MetricScore(1.0);
        $this->assertSame(1.0, $score->score);
    }

    public function test_below_zero_throws(): void
    {
        $this->expectException(MetricException::class);
        new MetricScore(-0.0001);
    }

    public function test_above_one_throws(): void
    {
        $this->expectException(MetricException::class);
        new MetricScore(1.0001);
    }

    public function test_nan_throws(): void
    {
        $this->expectException(MetricException::class);
        new MetricScore(NAN);
    }

    public function test_details_are_preserved(): void
    {
        $score = new MetricScore(0.5, ['expected' => 'a', 'actual' => 'b']);
        $this->assertSame(['expected' => 'a', 'actual' => 'b'], $score->details);
    }
}
