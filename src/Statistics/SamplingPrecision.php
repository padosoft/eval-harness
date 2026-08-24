<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Statistics;

/**
 * How small a change this run could actually have detected.
 *
 * An LLM is not deterministic, so a single execution of a sample is one draw,
 * not a measurement. Run the same golden dataset twice against an unchanged
 * pipeline and the macro score moves anyway. Every eval tool in this space
 * answers that with a fixed tolerance — "ignore drops under 5%" — which is a
 * guess wearing the costume of a threshold: on 3 repetitions a 5-point drop is
 * indistinguishable from a coin landing differently, and on 300 repetitions a
 * 5-point drop is a regression you have just been told to ignore.
 *
 * This class replaces the guess with the number the guess was standing in for:
 *
 *   - {@see differenceResolution()} — the smallest pass-rate difference this
 *     run can separate from noise, given how many repetitions it actually ran.
 *   - {@see requiredRepetitions()} — how many repetitions it would take to
 *     resolve the difference you care about.
 *   - {@see describe()} — both of those in a sentence an operator can act on.
 *
 * A regression gate that compares two runs should take its tolerance from the
 * resolution rather than from a constant, so the tolerance tightens by itself
 * as a suite gains repetitions instead of staying at whatever number was
 * plausible the day it was written.
 *
 * ## The maths, briefly
 *
 * Comparing two runs of the same dataset is a two-proportion comparison. The
 * standard error of the difference between two independent pass rates is
 * `sqrt(p₁(1-p₁)/n₁ + p₂(1-p₂)/n₂)`; with equal repetition counts and a shared
 * working estimate of `p` that is `sqrt(2p(1-p)/n)`, and a difference is
 * distinguishable at confidence `z` when it exceeds `z` times that. Inverting
 * for `n` gives `requiredRepetitions()`.
 *
 * The edge case is the one that matters most in practice, because it is where
 * healthy suites live: a row that passed every repetition has `p(1-p) = 0`, and
 * the formula above would report that zero repetitions suffice. It does not.
 * With no observed failures in `n` trials the 95% upper bound on the failure
 * rate is approximately `3/n` — the "rule of three" — so resolving a drop of
 * `δ` from a perfect record needs `n ≥ 3/δ`. That branch is why a 100% row is
 * reported as "could not have seen a 5-point drop" rather than as certainty.
 */
final class SamplingPrecision
{
    /** Default difference an operator is assumed to care about: five points. */
    public const DEFAULT_TARGET_DELTA = 0.05;

    /**
     * Smallest pass-rate difference distinguishable from sampling noise.
     *
     * Returns 1.0 (i.e. "nothing is distinguishable") for a run with fewer than
     * two repetitions, because a single draw carries no dispersion at all.
     */
    public static function differenceResolution(float $passRate, int $repetitions, float $z = WilsonInterval::Z_95): float
    {
        if ($repetitions < 2) {
            return 1.0;
        }

        $passRate = self::clampProportion($passRate);
        $variance = $passRate * (1.0 - $passRate);

        if ($variance <= 0.0) {
            // Rule of three: no observed failures still allows a failure rate
            // up to ~3/n, and that bound is the resolution.
            return min(1.0, round(3.0 / $repetitions, 6));
        }

        return min(1.0, round($z * sqrt((2 * $variance) / $repetitions), 6));
    }

    /**
     * Repetitions needed per run to resolve a difference of $targetDelta.
     */
    public static function requiredRepetitions(
        float $passRate,
        float $targetDelta = self::DEFAULT_TARGET_DELTA,
        float $z = WilsonInterval::Z_95,
    ): int {
        if ($targetDelta <= 0.0 || $targetDelta > 1.0) {
            return 0;
        }

        $passRate = self::clampProportion($passRate);
        $variance = $passRate * (1.0 - $passRate);

        if ($variance <= 0.0) {
            return (int) ceil(3.0 / $targetDelta);
        }

        return (int) ceil((2 * ($z ** 2) * $variance) / ($targetDelta ** 2));
    }

    /**
     * Everything a report or a gate needs, in one payload.
     *
     * @return array{
     *     repetitions: int,
     *     pass_rate: float,
     *     confidence: float,
     *     resolution: float,
     *     target_delta: float,
     *     target_resolvable: bool,
     *     required_repetitions: int,
     *     summary: string
     * }
     */
    public static function describe(
        float $passRate,
        int $repetitions,
        float $targetDelta = self::DEFAULT_TARGET_DELTA,
        float $z = WilsonInterval::Z_95,
    ): array {
        $resolution = self::differenceResolution($passRate, $repetitions, $z);
        $required = self::requiredRepetitions($passRate, $targetDelta, $z);
        $resolvable = $repetitions >= 2 && $resolution <= $targetDelta;

        return [
            'repetitions' => $repetitions,
            'pass_rate' => round(self::clampProportion($passRate), 6),
            'confidence' => round(self::confidenceFor($z), 4),
            'resolution' => $resolution,
            'target_delta' => round($targetDelta, 6),
            'target_resolvable' => $resolvable,
            'required_repetitions' => $required,
            'summary' => self::summarise($repetitions, $resolution, $targetDelta, $required, $resolvable),
        ];
    }

    private static function summarise(
        int $repetitions,
        float $resolution,
        float $targetDelta,
        int $required,
        bool $resolvable,
    ): string {
        $targetPoints = self::points($targetDelta);

        if ($repetitions < 2) {
            return sprintf(
                'Single execution per sample: no dispersion was measured, so no difference is distinguishable from noise. '
                .'Resolving %s needs at least %d repetitions (--repetitions=%d).',
                $targetPoints,
                $required,
                $required,
            );
        }

        if ($resolvable) {
            return sprintf(
                '%d repetitions resolve differences down to %s, which covers the %s you asked about.',
                $repetitions,
                self::points($resolution),
                $targetPoints,
            );
        }

        return sprintf(
            '%d repetitions only resolve differences above %s, so a %s change is not distinguishable from noise here. '
            .'Resolving %s needs at least %d repetitions (--repetitions=%d).',
            $repetitions,
            self::points($resolution),
            $targetPoints,
            $targetPoints,
            $required,
            $required,
        );
    }

    private static function points(float $value): string
    {
        return rtrim(rtrim(number_format($value * 100, 1, '.', ''), '0'), '.').' points';
    }

    /**
     * Two-sided confidence level implied by a z value.
     *
     * PHP has no `erf`, so this is the Abramowitz & Stegun 7.1.26 rational
     * approximation (absolute error below 1.5e-7) — far more precision than a
     * label like "0.95" in a report needs, and it keeps the package free of a
     * maths extension the host may not have compiled in.
     */
    private static function confidenceFor(float $z): float
    {
        return self::erf(abs($z) / sqrt(2));
    }

    private static function erf(float $x): float
    {
        $sign = $x < 0 ? -1.0 : 1.0;
        $x = abs($x);

        $t = 1.0 / (1.0 + (0.3275911 * $x));
        $series = $t * (0.254829592
            + $t * (-0.284496736
                + $t * (1.421413741
                    + $t * (-1.453152027
                        + $t * 1.061405429))));

        return $sign * (1.0 - ($series * exp(-($x ** 2))));
    }

    private static function clampProportion(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
