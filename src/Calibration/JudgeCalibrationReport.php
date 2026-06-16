<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Calibration;

/**
 * Immutable result of a judge-calibration run.
 *
 * Agreement is measured on VERDICTS (pass/fail), not raw judge scores:
 * each judge raw score is converted to a verdict via the configured
 * `verdict_pass_threshold`, then compared against the human verdict.
 *
 * `lengthBiasCorrelation` is a Spearman rank correlation in [-1, 1]
 * between answer length and judge score; a high absolute value warns
 * that the judge may be rewarding length rather than quality.
 *
 * `selfPreferenceViolation` is true when the judge model equals the
 * model under test and the self-preference guard is enabled.
 */
final class JudgeCalibrationReport
{
    /**
     * @param  array{true_pass: int, true_fail: int, false_pass: int, false_fail: int}  $confusion
     */
    public function __construct(
        private readonly float $agreementRate,
        private readonly array $confusion,
        private readonly float $lengthBiasCorrelation,
        private readonly bool $selfPreferenceViolation,
        private readonly int $caseCount,
        private readonly float $verdictPassThreshold,
    ) {}

    public function agreementRate(): float
    {
        return $this->agreementRate;
    }

    /**
     * @return array{true_pass: int, true_fail: int, false_pass: int, false_fail: int}
     */
    public function confusion(): array
    {
        return $this->confusion;
    }

    public function lengthBiasCorrelation(): float
    {
        return $this->lengthBiasCorrelation;
    }

    public function lengthBiasWarned(float $threshold): bool
    {
        return abs($this->lengthBiasCorrelation) >= $threshold;
    }

    public function selfPreferenceViolation(): bool
    {
        return $this->selfPreferenceViolation;
    }

    public function caseCount(): int
    {
        return $this->caseCount;
    }

    public function meetsThreshold(float $minAgreement): bool
    {
        return $this->agreementRate >= $minAgreement;
    }

    /**
     * @return array{
     *     agreement_rate: float,
     *     case_count: int,
     *     verdict_pass_threshold: float,
     *     confusion: array{true_pass: int, true_fail: int, false_pass: int, false_fail: int},
     *     length_bias_correlation: float,
     *     self_preference_violation: bool
     * }
     */
    public function toArray(): array
    {
        return [
            'agreement_rate' => $this->agreementRate,
            'case_count' => $this->caseCount,
            'verdict_pass_threshold' => $this->verdictPassThreshold,
            'confusion' => $this->confusion,
            'length_bias_correlation' => $this->lengthBiasCorrelation,
            'self_preference_violation' => $this->selfPreferenceViolation,
        ];
    }
}
