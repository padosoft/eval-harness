<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Regression;

/**
 * One dataset row, seen across two runs.
 *
 * Two things are reported and they are not the same thing:
 *
 *   - **status** — what happened. A drop is a drop; the row got worse.
 *   - **confident** — whether this run had enough repetitions to tell that
 *     drop apart from the pipeline sampling differently.
 *
 * Keeping them separate is the whole point. Collapsing them into one verdict
 * forces a choice between two bad options: hide real breaks that a
 * single-execution run cannot prove (and ship the regression), or report noise
 * as fact (and train everyone to ignore the gate). Reporting both lets the gate
 * fail on what moved while the operator can still see which failures the run
 * could actually stand behind.
 */
final class RowComparison
{
    public const STATUS_REGRESSED = 'regressed';

    public const STATUS_IMPROVED = 'improved';

    public const STATUS_STABLE = 'stable';

    public const STATUS_ADDED = 'added';

    public const STATUS_REMOVED = 'removed';

    /**
     * @param  array{pass_rate: float|null, score_mean: float|null, score_stddev: float|null, repetitions: int}|null  $before
     * @param  array{pass_rate: float|null, score_mean: float|null, score_stddev: float|null, repetitions: int}|null  $after
     */
    public function __construct(
        public readonly string $rowHash,
        public readonly string $sampleId,
        public readonly string $status,
        public readonly ?array $before,
        public readonly ?array $after,
        public readonly ?float $passRateDelta,
        public readonly ?float $scoreDelta,
        public readonly bool $confident,
        public readonly float $resolution,
    ) {}

    public function isRegression(): bool
    {
        return $this->status === self::STATUS_REGRESSED;
    }

    /**
     * A row that was passing on every execution and no longer is.
     *
     * The subset of regressions worth waking somebody for: a row sliding from
     * 0.62 to 0.57 is a trend, a row that was green on every repetition and is
     * now red is a break.
     */
    public function isNewlyFailing(): bool
    {
        // Both sides read once into locals. `before` and `after` are null for
        // added and removed rows, and repeating a null-coalescing index across
        // three clauses invites the next edit to drop one of them.
        $before = $this->before['pass_rate'] ?? null;
        $after = $this->after['pass_rate'] ?? null;

        return $this->isRegression()
            && $before !== null
            && $before >= 1.0
            && ($after ?? 1.0) < 1.0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'row_hash' => $this->rowHash,
            'sample_id' => $this->sampleId,
            'status' => $this->status,
            'confident' => $this->confident,
            'newly_failing' => $this->isNewlyFailing(),
            'resolution' => round($this->resolution, 6),
            'pass_rate_delta' => $this->passRateDelta === null ? null : round($this->passRateDelta, 6),
            'score_delta' => $this->scoreDelta === null ? null : round($this->scoreDelta, 6),
            'before' => $this->before,
            'after' => $this->after,
        ];
    }
}
