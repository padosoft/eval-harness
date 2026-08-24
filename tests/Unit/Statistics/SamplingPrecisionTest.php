<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Statistics;

use Padosoft\EvalHarness\Statistics\SamplingPrecision;
use PHPUnit\Framework\TestCase;

final class SamplingPrecisionTest extends TestCase
{
    public function test_a_single_execution_resolves_nothing(): void
    {
        $this->assertSame(1.0, SamplingPrecision::differenceResolution(0.8, 1));

        $described = SamplingPrecision::describe(0.8, 1);

        $this->assertFalse($described['target_resolvable']);
        $this->assertStringContainsString('Single execution per sample', $described['summary']);
        $this->assertGreaterThan(0, $described['required_repetitions']);
    }

    public function test_resolution_improves_with_repetitions(): void
    {
        $three = SamplingPrecision::differenceResolution(0.5, 3);
        $thirty = SamplingPrecision::differenceResolution(0.5, 30);
        $threeHundred = SamplingPrecision::differenceResolution(0.5, 300);

        $this->assertGreaterThan($thirty, $three);
        $this->assertGreaterThan($threeHundred, $thirty);
    }

    /**
     * The headline claim of the feature: three repetitions cannot see a five
     * point regression, and the package says so instead of pretending a fixed
     * epsilon settled the question.
     */
    public function test_three_repetitions_cannot_resolve_five_points(): void
    {
        $described = SamplingPrecision::describe(0.667, 3);

        $this->assertFalse($described['target_resolvable']);
        $this->assertGreaterThan(0.05, $described['resolution']);
        $this->assertGreaterThan(100, $described['required_repetitions']);
        $this->assertStringContainsString('not distinguishable from noise', $described['summary']);
        $this->assertStringContainsString('--repetitions=', $described['summary']);
    }

    public function test_enough_repetitions_report_the_target_as_resolvable(): void
    {
        $required = SamplingPrecision::requiredRepetitions(0.5, 0.05);
        $described = SamplingPrecision::describe(0.5, $required);

        $this->assertTrue($described['target_resolvable']);
        $this->assertLessThanOrEqual(0.05, $described['resolution']);
        $this->assertStringContainsString('resolve differences down to', $described['summary']);
    }

    /**
     * A perfect pass rate has zero variance, so the normal approximation would
     * claim that no repetitions are needed at all. The rule of three is what
     * keeps the answer honest for the healthy suites where this matters most.
     */
    public function test_a_perfect_pass_rate_uses_the_rule_of_three(): void
    {
        $this->assertSame(60, SamplingPrecision::requiredRepetitions(1.0, 0.05));
        $this->assertSame(30, SamplingPrecision::requiredRepetitions(0.0, 0.10));

        $this->assertEqualsWithDelta(0.3, SamplingPrecision::differenceResolution(1.0, 10), 0.000001);
    }

    public function test_required_repetitions_grow_as_the_target_shrinks(): void
    {
        $coarse = SamplingPrecision::requiredRepetitions(0.5, 0.10);
        $fine = SamplingPrecision::requiredRepetitions(0.5, 0.01);

        $this->assertGreaterThan($coarse, $fine);
    }

    public function test_invalid_target_delta_yields_zero_required_repetitions(): void
    {
        $this->assertSame(0, SamplingPrecision::requiredRepetitions(0.5, 0.0));
        $this->assertSame(0, SamplingPrecision::requiredRepetitions(0.5, -0.2));
        $this->assertSame(0, SamplingPrecision::requiredRepetitions(0.5, 1.5));
    }

    public function test_describe_reports_the_confidence_level(): void
    {
        $described = SamplingPrecision::describe(0.5, 10);

        $this->assertEqualsWithDelta(0.95, $described['confidence'], 0.001);
    }

    public function test_pass_rates_outside_the_unit_range_are_clamped(): void
    {
        $this->assertSame(
            SamplingPrecision::differenceResolution(1.0, 5),
            SamplingPrecision::differenceResolution(1.4, 5),
        );
        $this->assertSame(
            SamplingPrecision::differenceResolution(0.0, 5),
            SamplingPrecision::differenceResolution(-0.3, 5),
        );
    }
}
