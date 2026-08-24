<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Regression;

/**
 * Run-level pass/fail policy applied after a comparison.
 *
 * Separate from {@see RunComparator} on purpose: the comparator answers what
 * changed, and that answer is the same for everybody. Whether those changes
 * should fail a build is a decision that differs per lane — a PR gate and a
 * nightly run look at identical numbers and want different outcomes.
 *
 * `confidentOnly` is the knob that matters. Left off (the default) the gate
 * counts every regression, which is what a PR gate wants: a row that went from
 * green to red is a break worth stopping for, even on a single-execution run
 * that cannot prove it statistically. Turned on, the gate counts only the
 * regressions the run had the repetitions to stand behind — the right setting
 * for a scheduled run with enough repetitions to be sure, where a false alarm
 * costs more than a day's delay.
 */
final class RegressionGate
{
    public function __construct(
        public readonly int $maxRegressions = 0,
        public readonly ?float $minMacroF1 = null,
        public readonly ?float $minPassRate = null,
        public readonly bool $confidentOnly = false,
    ) {}

    /**
     * @param  array<string, mixed>  $currentReport  decoded JSON report of the run being gated
     * @return array{passed: bool, failures: list<string>, counted_regressions: int}
     */
    public function evaluate(RunComparison $comparison, array $currentReport = []): array
    {
        $regressions = $this->confidentOnly
            ? $comparison->confidentRegressions()
            : $comparison->regressed();

        $failures = [];

        if (count($regressions) > $this->maxRegressions) {
            $failures[] = sprintf(
                '%d %srow%s regressed against %s (allowed: %d)%s',
                count($regressions),
                $this->confidentOnly ? 'confident ' : '',
                count($regressions) === 1 ? '' : 's',
                $comparison->referenceLabel ?? 'the reference run',
                $this->maxRegressions,
                $this->confidentOnly ? '' : $this->confidenceNote($comparison),
            );
        }

        $macroF1 = $this->float($currentReport, 'macro_f1');
        if ($this->minMacroF1 !== null && ($macroF1 === null || $macroF1 < $this->minMacroF1)) {
            $failures[] = sprintf(
                'macro-F1 %s is below the minimum %.4f',
                $macroF1 === null ? 'n/a' : sprintf('%.4f', $macroF1),
                $this->minMacroF1,
            );
        }

        $passRate = $this->float($currentReport, 'pass_rate');
        if ($this->minPassRate !== null && ($passRate === null || $passRate < $this->minPassRate)) {
            $failures[] = sprintf(
                'pass rate %s is below the minimum %.4f',
                $passRate === null ? 'n/a' : sprintf('%.4f', $passRate),
                $this->minPassRate,
            );
        }

        return [
            'passed' => $failures === [],
            'failures' => $failures,
            'counted_regressions' => count($regressions),
        ];
    }

    /**
     * When a gate fails on regressions the run could not prove, say so in the
     * same breath. A failure message that hides "and none of these were
     * distinguishable from noise" is how a gate loses its audience.
     */
    private function confidenceNote(RunComparison $comparison): string
    {
        $confident = count($comparison->confidentRegressions());
        $total = count($comparison->regressed());

        if ($confident === $total) {
            return '';
        }

        return sprintf(
            '; %d of %d exceed this run\'s detectable difference of %.1f points, the rest are within sampling noise',
            $confident,
            $total,
            $comparison->resolution * 100,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function float(array $payload, string $key): ?float
    {
        $value = $payload[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }
}
