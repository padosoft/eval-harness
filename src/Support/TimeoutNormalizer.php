<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Support;

/**
 * Normalises HTTP timeout values from config / env input.
 *
 * The Laravel HTTP client interprets `Http::timeout(0)` as "no
 * timeout" (block forever), so a typo in `EVAL_HARNESS_*_TIMEOUT`
 * (e.g. `abc`) cast directly to `(int)` collapses to `0` and makes
 * eval runs hang on a misconfigured environment instead of falling
 * back to the documented default.
 *
 * `normalize()` accepts any user-shaped value and returns:
 *   - the numeric input rounded down to a positive int when it is a
 *     valid positive number,
 *   - the supplied default when the input is null, empty, non-numeric,
 *     zero, or negative.
 *
 * The default itself is also validated: if a caller passes a
 * non-positive default the helper returns a hard-coded fallback of
 * `1` second so we never accidentally enable infinite blocking.
 */
final class TimeoutNormalizer
{
    public static function normalize(mixed $value, int $default): int
    {
        $safeDefault = $default >= 1 ? $default : 1;

        if ($value === null) {
            return $safeDefault;
        }

        if (is_int($value)) {
            return $value >= 1 ? $value : $safeDefault;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return $safeDefault;
            }

            $filtered = filter_var(
                $trimmed,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );

            if (is_int($filtered)) {
                return $filtered;
            }

            // Allow "30.0" / "30.5" — round down to a positive int.
            $float = filter_var($trimmed, FILTER_VALIDATE_FLOAT);
            if (is_float($float) && $float >= 1.0) {
                return (int) floor($float);
            }

            return $safeDefault;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value) || $value < 1.0) {
                return $safeDefault;
            }

            return (int) floor($value);
        }

        return $safeDefault;
    }
}
