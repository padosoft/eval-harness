<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Statistics;

use Padosoft\EvalHarness\Statistics\WilsonInterval;
use PHPUnit\Framework\TestCase;

final class WilsonIntervalTest extends TestCase
{
    public function test_zero_trials_returns_the_whole_range(): void
    {
        $interval = WilsonInterval::forProportion(0, 0);

        $this->assertSame(0.0, $interval['low']);
        $this->assertSame(1.0, $interval['high']);
    }

    /**
     * The reason this package uses Wilson rather than the textbook normal
     * interval: a row that passed every repetition must not be reported as
     * certain, because three successes out of three is not proof.
     */
    public function test_a_perfect_run_still_has_a_non_zero_interval(): void
    {
        $interval = WilsonInterval::forProportion(3, 3);

        $this->assertSame(1.0, $interval['point']);
        $this->assertSame(1.0, $interval['high']);
        $this->assertGreaterThan(0.2, $interval['low']);
        $this->assertLessThan(1.0, $interval['low']);
    }

    public function test_a_run_that_never_passed_still_has_a_non_zero_interval(): void
    {
        $interval = WilsonInterval::forProportion(0, 3);

        $this->assertSame(0.0, $interval['point']);
        $this->assertSame(0.0, $interval['low']);
        $this->assertGreaterThan(0.0, $interval['high']);
        $this->assertLessThan(0.8, $interval['high']);
    }

    public function test_interval_matches_the_published_value_for_a_known_case(): void
    {
        // 2 of 3, 95%: the standard Wilson result is roughly [0.208, 0.939].
        $interval = WilsonInterval::forProportion(2, 3);

        $this->assertEqualsWithDelta(0.2076, $interval['low'], 0.001);
        $this->assertEqualsWithDelta(0.9391, $interval['high'], 0.001);
    }

    public function test_more_trials_narrow_the_interval(): void
    {
        $few = WilsonInterval::forProportion(5, 10);
        $many = WilsonInterval::forProportion(50, 100);

        $this->assertSame(0.5, $few['point']);
        $this->assertSame(0.5, $many['point']);
        $this->assertLessThan($few['half_width'], $many['half_width']);
    }

    public function test_interval_never_leaves_the_unit_range(): void
    {
        foreach ([[0, 1], [1, 1], [1, 2], [7, 9], [99, 100]] as [$successes, $trials]) {
            $interval = WilsonInterval::forProportion($successes, $trials);

            $this->assertGreaterThanOrEqual(0.0, $interval['low']);
            $this->assertLessThanOrEqual(1.0, $interval['high']);
            $this->assertLessThanOrEqual($interval['high'], $interval['low']);
        }
    }

    public function test_successes_above_trials_are_clamped(): void
    {
        $this->assertSame(
            WilsonInterval::forProportion(3, 3),
            WilsonInterval::forProportion(9, 3),
        );
    }

    public function test_half_width_matches_the_returned_bounds(): void
    {
        foreach ([[1, 3], [2, 3], [7, 9], [13, 17], [99, 100]] as [$successes, $trials]) {
            $interval = WilsonInterval::forProportion($successes, $trials);

            $this->assertSame(
                round(($interval['high'] - $interval['low']) / 2, 6),
                $interval['half_width'],
                'half_width must be derivable from the bounds a caller was handed',
            );
        }
    }

    public function test_higher_confidence_widens_the_interval(): void
    {
        $ninetyFive = WilsonInterval::forProportion(5, 10, WilsonInterval::Z_95);
        $ninetyNine = WilsonInterval::forProportion(5, 10, WilsonInterval::Z_99);

        $this->assertGreaterThan($ninetyFive['half_width'], $ninetyNine['half_width']);
    }
}
