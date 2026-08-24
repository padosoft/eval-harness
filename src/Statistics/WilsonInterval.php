<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Statistics;

/**
 * Wilson score interval for a binomial proportion.
 *
 * Why Wilson and not the textbook normal ("Wald") interval: an eval run
 * repeats a handful of times, and the interesting pass rates sit at the
 * edges — a row that passed 3/3 or 0/3. Wald returns a zero-width interval
 * for both ("100% ± 0"), which is the single most misleading number a
 * regression gate can be handed. Wilson stays inside [0, 1], keeps a
 * non-zero width at the edges, and behaves at the small trial counts an
 * eval actually runs at.
 *
 * @see SamplingPrecision for the run-level "is this difference real?" answer
 *      built on top of this.
 */
final class WilsonInterval
{
    /** Two-sided 95% confidence (the default everywhere in this package). */
    public const Z_95 = 1.959964;

    /** Two-sided 99% confidence, for gates that must be very sure. */
    public const Z_99 = 2.575829;

    /**
     * @return array{low: float, high: float, point: float, half_width: float}
     */
    public static function forProportion(int $successes, int $trials, float $z = self::Z_95): array
    {
        if ($trials <= 0) {
            return ['low' => 0.0, 'high' => 1.0, 'point' => 0.0, 'half_width' => 0.5];
        }

        $successes = max(0, min($successes, $trials));
        $point = $successes / $trials;

        $zSquared = $z ** 2;
        $denominator = 1.0 + ($zSquared / $trials);
        $centre = ($point + ($zSquared / (2 * $trials))) / $denominator;
        $spread = ($z / $denominator) * sqrt(
            (($point * (1.0 - $point)) / $trials) + ($zSquared / (4 * ($trials ** 2))),
        );

        $low = max(0.0, $centre - $spread);
        $high = min(1.0, $centre + $spread);

        return [
            'low' => round($low, 6),
            'high' => round($high, 6),
            'point' => round($point, 6),
            'half_width' => round(($high - $low) / 2, 6),
        ];
    }
}
