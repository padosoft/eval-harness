<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Regression;

/**
 * The outcome of comparing a run against a reference run, row by row.
 *
 * Aggregate deltas (macro-F1 moved by -0.03) say that something changed
 * somewhere. This says *which rows*, which is the difference between a number
 * to argue about in a stand-up and a list to fix.
 */
final class RunComparison
{
    /**
     * @param  list<RowComparison>  $rows
     */
    public function __construct(
        public readonly string $dataset,
        public readonly ?string $referenceLabel,
        public readonly array $rows,
        public readonly ?float $macroF1Delta,
        public readonly ?float $passRateDelta,
        public readonly float $resolution,
        public readonly bool $resolutionIsStatistical,
    ) {}

    /**
     * @return list<RowComparison>
     */
    public function regressed(): array
    {
        return $this->withStatus(RowComparison::STATUS_REGRESSED);
    }

    /**
     * Regressions this run had the repetitions to stand behind.
     *
     * @return list<RowComparison>
     */
    public function confidentRegressions(): array
    {
        return array_values(array_filter(
            $this->regressed(),
            static fn (RowComparison $row): bool => $row->confident,
        ));
    }

    /**
     * @return list<RowComparison>
     */
    public function newlyFailing(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (RowComparison $row): bool => $row->isNewlyFailing(),
        ));
    }

    /**
     * @return list<RowComparison>
     */
    public function improved(): array
    {
        return $this->withStatus(RowComparison::STATUS_IMPROVED);
    }

    /**
     * @return list<RowComparison>
     */
    public function added(): array
    {
        return $this->withStatus(RowComparison::STATUS_ADDED);
    }

    /**
     * @return list<RowComparison>
     */
    public function removed(): array
    {
        return $this->withStatus(RowComparison::STATUS_REMOVED);
    }

    public function hasChanges(): bool
    {
        foreach ($this->rows as $row) {
            if ($row->status !== RowComparison::STATUS_STABLE) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => RegressionSchema::VERSION,
            'dataset' => $this->dataset,
            'reference' => $this->referenceLabel,
            'resolution' => round($this->resolution, 6),
            'resolution_is_statistical' => $this->resolutionIsStatistical,
            'macro_f1_delta' => $this->macroF1Delta === null ? null : round($this->macroF1Delta, 6),
            'pass_rate_delta' => $this->passRateDelta === null ? null : round($this->passRateDelta, 6),
            'counts' => [
                'regressed' => count($this->regressed()),
                'regressed_confident' => count($this->confidentRegressions()),
                'newly_failing' => count($this->newlyFailing()),
                'improved' => count($this->improved()),
                'added' => count($this->added()),
                'removed' => count($this->removed()),
                'compared' => count($this->rows),
            ],
            'rows' => array_map(static fn (RowComparison $row): array => $row->toArray(), $this->rows),
        ];
    }

    /**
     * @return list<RowComparison>
     */
    private function withStatus(string $status): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (RowComparison $row): bool => $row->status === $status,
        ));
    }
}
