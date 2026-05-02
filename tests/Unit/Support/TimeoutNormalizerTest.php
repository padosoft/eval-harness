<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Support;

use Padosoft\EvalHarness\Support\TimeoutNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Regression suite for the timeout int-cast footgun: a misconfigured
 * env var (e.g. `EVAL_HARNESS_JUDGE_TIMEOUT=abc`) cast directly to
 * `(int)` collapses to `0`, which Laravel's HTTP client interprets as
 * "no timeout" — the eval run then hangs forever instead of falling
 * back to the documented default.
 *
 * TimeoutNormalizer enforces a positive int with the supplied
 * fallback for every malformed input shape.
 */
final class TimeoutNormalizerTest extends TestCase
{
    public function test_positive_int_passes_through(): void
    {
        $this->assertSame(30, TimeoutNormalizer::normalize(30, 60));
    }

    public function test_positive_numeric_string_parses(): void
    {
        $this->assertSame(45, TimeoutNormalizer::normalize('45', 60));
    }

    public function test_positive_float_floors_to_int(): void
    {
        $this->assertSame(45, TimeoutNormalizer::normalize(45.9, 60));
        $this->assertSame(45, TimeoutNormalizer::normalize('45.9', 60));
    }

    public function test_zero_falls_back_to_default(): void
    {
        $this->assertSame(60, TimeoutNormalizer::normalize(0, 60));
        $this->assertSame(60, TimeoutNormalizer::normalize('0', 60));
    }

    public function test_negative_falls_back_to_default(): void
    {
        $this->assertSame(60, TimeoutNormalizer::normalize(-10, 60));
        $this->assertSame(60, TimeoutNormalizer::normalize('-10', 60));
    }

    public function test_non_numeric_string_falls_back_to_default(): void
    {
        $this->assertSame(60, TimeoutNormalizer::normalize('abc', 60));
        $this->assertSame(60, TimeoutNormalizer::normalize('30s', 60));
    }

    public function test_empty_string_falls_back_to_default(): void
    {
        $this->assertSame(60, TimeoutNormalizer::normalize('', 60));
        $this->assertSame(60, TimeoutNormalizer::normalize('   ', 60));
    }

    public function test_null_falls_back_to_default(): void
    {
        $this->assertSame(60, TimeoutNormalizer::normalize(null, 60));
    }

    public function test_nan_and_inf_fall_back_to_default(): void
    {
        $this->assertSame(60, TimeoutNormalizer::normalize(NAN, 60));
        $this->assertSame(60, TimeoutNormalizer::normalize(INF, 60));
        $this->assertSame(60, TimeoutNormalizer::normalize(-INF, 60));
    }

    public function test_unsupported_types_fall_back_to_default(): void
    {
        $this->assertSame(60, TimeoutNormalizer::normalize(['a'], 60));
        $this->assertSame(60, TimeoutNormalizer::normalize(true, 60));
        $this->assertSame(60, TimeoutNormalizer::normalize(false, 60));
        $this->assertSame(60, TimeoutNormalizer::normalize(new \stdClass, 60));
    }

    public function test_non_positive_default_is_clamped_to_one(): void
    {
        // Defensive: even a buggy default of 0 must NOT collapse the
        // actual timeout value to 0 — that would re-introduce the
        // exact footgun this helper exists to prevent.
        $this->assertSame(1, TimeoutNormalizer::normalize('abc', 0));
        $this->assertSame(1, TimeoutNormalizer::normalize('abc', -5));
    }
}
