<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Statistics;

use Padosoft\EvalHarness\Statistics\SamplingPrecision;
use PHPUnit\Framework\TestCase;

/**
 * The variance entry points, which is what a report actually uses.
 *
 * Pooling the pass rate across rows answers a different question from the one
 * a per-row gate asks, and gets it wrong in a way that matters: a dataset where
 * half the rows always pass and half always fail pools to p = 0.5 and reports
 * substantial noise for a suite that never wavered.
 */
final class SamplingPrecisionVarianceTest extends TestCase
{
    public function test_a_perfectly_steady_suite_reports_no_measured_noise(): void
    {
        // Half the rows always pass, half always fail: pooled p = 0.5, but every
        // row agreed with itself, so the within-row variance is zero.
        $pooled = SamplingPrecision::differenceResolution(0.5, 3);
        $withinRow = SamplingPrecision::differenceResolutionFromVariance(0.0, 3);

        $this->assertEqualsWithDelta(0.8, $pooled, 0.01);
        $this->assertEqualsWithDelta(1.0, $withinRow, 0.000001);
        $this->assertLessThan($pooled, SamplingPrecision::differenceResolutionFromVariance(0.0, 10));
    }

    public function test_steady_rows_fall_back_to_the_rule_of_three(): void
    {
        $this->assertEqualsWithDelta(0.15, SamplingPrecision::differenceResolutionFromVariance(0.0, 20), 0.000001);
        $this->assertSame(60, SamplingPrecision::requiredRepetitionsFromVariance(0.0, 0.05));
    }

    public function test_observed_variance_drives_the_resolution(): void
    {
        $steady = SamplingPrecision::differenceResolutionFromVariance(0.0, 10);
        $flapping = SamplingPrecision::differenceResolutionFromVariance(0.25, 10);

        $this->assertGreaterThan($steady, $flapping);
    }

    public function test_the_summary_explains_a_zero_variance_limit(): void
    {
        $described = SamplingPrecision::describeFromVariance(variance: 0.0, repetitions: 5, passRate: 0.5);

        $this->assertStringContainsString('Every row agreed with itself', $described['summary']);
        $this->assertSame(0.6, $described['resolution']);
    }

    public function test_the_summary_omits_the_note_when_rows_disagreed(): void
    {
        $described = SamplingPrecision::describeFromVariance(variance: 0.25, repetitions: 5, passRate: 0.5);

        $this->assertStringNotContainsString('Every row agreed with itself', $described['summary']);
    }

    public function test_negative_variance_is_clamped(): void
    {
        $this->assertSame(
            SamplingPrecision::differenceResolutionFromVariance(0.0, 10),
            SamplingPrecision::differenceResolutionFromVariance(-0.5, 10),
        );
    }

    public function test_pass_rate_helpers_delegate_to_the_variance_ones(): void
    {
        $this->assertSame(
            SamplingPrecision::differenceResolutionFromVariance(0.25, 8),
            SamplingPrecision::differenceResolution(0.5, 8),
        );
        $this->assertSame(
            SamplingPrecision::requiredRepetitionsFromVariance(0.25, 0.05),
            SamplingPrecision::requiredRepetitions(0.5, 0.05),
        );
    }
}
