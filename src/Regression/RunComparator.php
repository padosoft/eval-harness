<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Regression;

use Padosoft\EvalHarness\Datasets\RowHash;
use Padosoft\EvalHarness\Statistics\SamplingPrecision;

/**
 * Joins two runs of the same dataset row by row and says what moved.
 *
 * ## Why rows join on a hash and not on an id
 *
 * Ids get renamed, rows get reordered, and a row whose question was rewritten
 * keeps its id while becoming a different test. Joining on the id therefore
 * compares things that are not the same row and misses things that are. The
 * join key is {@see RowHash}, taken over the
 * input and the expected output.
 *
 * ## Where the tolerance comes from
 *
 * Every other tool in this space ships a constant — "ignore drops under 5%" —
 * and that constant is wrong in both directions at once: too tight for a run of
 * three executions, where half the scale is noise, and far too loose for a run
 * of three hundred, where it hides real regressions. Here the tolerance is
 * {@see SamplingPrecision::differenceResolution()} computed from the run's own
 * repetitions and pass rate, so it tightens by itself as a suite gains
 * repetitions. An explicit epsilon is still accepted for callers who need a
 * fixed number in a contract.
 *
 * ## Status and confidence are separate
 *
 * A row that dropped is reported as regressed whatever the repetition count,
 * because a real break on a single-execution run is still a real break. What
 * changes with repetitions is whether the drop is *provable*, and that travels
 * as {@see RowComparison::$confident}. A gate can count either — see
 * {@see RegressionGate} — but the report always shows both, so nobody has to
 * choose between shipping regressions and chasing noise.
 */
final class RunComparator
{
    /**
     * Compare two decoded JSON report payloads (current, reference).
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $reference
     */
    public function compare(array $current, array $reference, ?string $referenceLabel = null, ?float $epsilon = null): RunComparison
    {
        $resolution = $epsilon ?? $this->resolutionFor($current);
        $joinKey = $this->joinKeyFor($current, $reference);

        // A reference with nothing to join on cannot produce a comparison, and
        // the failure mode to avoid is the quiet one: joining on nothing makes
        // every current row look "added", which reads as zero regressions and
        // passes the gate. Say so instead, and let the caller treat it the way
        // it treats a missing baseline.
        if ($joinKey === null) {
            return new RunComparison(
                dataset: is_string($current['dataset'] ?? null) ? $current['dataset'] : '',
                referenceLabel: $referenceLabel,
                rows: [],
                macroF1Delta: null,
                passRateDelta: null,
                resolution: $resolution,
                resolutionIsStatistical: $epsilon === null,
                joinKey: null,
                incomparableReason: $this->incomparableReason($current, $reference),
            );
        }

        $currentRows = $this->rowsBy($current, $joinKey);
        $referenceRows = $this->rowsBy($reference, $joinKey);
        $rows = [];

        foreach ($currentRows as $hash => $after) {
            $before = $referenceRows[$hash] ?? null;

            if ($before === null) {
                $rows[] = $this->row($hash, $after['sample_id'], RowComparison::STATUS_ADDED, null, $after, null, null, false, $resolution);

                continue;
            }

            $passRateDelta = $this->delta($before['pass_rate'], $after['pass_rate']);
            $scoreDelta = $this->delta($before['score_mean'], $after['score_mean']);

            [$status, $confident] = $this->verdict($passRateDelta, $scoreDelta, $resolution);

            $rows[] = $this->row($hash, $after['sample_id'], $status, $before, $after, $passRateDelta, $scoreDelta, $confident, $resolution);
        }

        foreach ($referenceRows as $hash => $before) {
            if (isset($currentRows[$hash])) {
                continue;
            }

            $rows[] = $this->row($hash, $before['sample_id'], RowComparison::STATUS_REMOVED, $before, null, null, null, false, $resolution);
        }

        return new RunComparison(
            dataset: is_string($current['dataset'] ?? null) ? $current['dataset'] : '',
            referenceLabel: $referenceLabel,
            rows: $rows,
            macroF1Delta: $this->delta($this->float($reference, 'macro_f1'), $this->float($current, 'macro_f1')),
            passRateDelta: $this->delta($this->float($reference, 'pass_rate'), $this->float($current, 'pass_rate')),
            resolution: $resolution,
            resolutionIsStatistical: $epsilon === null,
            joinKey: $joinKey,
        );
    }

    /**
     * What to join the two runs on.
     *
     * `row_hash` when both sides carry it — the identity that survives renames
     * and reordering. `id` when they do not, which happens against a report
     * written before hashes existed: a degraded join is still a real
     * comparison, and far better than declaring every row new. Null when the
     * reference has no per-row data at all.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $reference
     */
    private function joinKeyFor(array $current, array $reference): ?string
    {
        $currentAggregates = $this->aggregatesOf($current);
        $referenceAggregates = $this->aggregatesOf($reference);

        if ($currentAggregates === [] || $referenceAggregates === []) {
            return null;
        }

        if ($this->allCarryHashes($currentAggregates) && $this->allCarryHashes($referenceAggregates)) {
            return 'row_hash';
        }

        return 'id';
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $reference
     */
    private function incomparableReason(array $current, array $reference): string
    {
        if ($this->aggregatesOf($reference) === []) {
            return 'the reference report has no per-row data (sample_aggregates), so no row can be compared against it; re-run and promote a report produced by this version';
        }

        return 'this run produced no per-row data to compare';
    }

    /**
     * @param  array<string, mixed>  $report
     * @return list<array<string, mixed>>
     */
    private function aggregatesOf(array $report): array
    {
        $aggregates = $report['sample_aggregates'] ?? null;

        if (! is_array($aggregates)) {
            return [];
        }

        return array_values(array_filter($aggregates, static fn (mixed $entry): bool => is_array($entry)));
    }

    /**
     * @param  list<array<string, mixed>>  $aggregates
     */
    private function allCarryHashes(array $aggregates): bool
    {
        foreach ($aggregates as $aggregate) {
            $hash = $aggregate['row_hash'] ?? null;

            if (! is_string($hash) || $hash === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * What happened to this row, and whether the run could prove it.
     *
     * The two travel together because confidence has to be judged on the axis
     * that produced the verdict. Judged independently, a row whose pass rate
     * slipped a hair inside the noise while its mean score rose sharply would
     * be reported as a *confident regression* — regressed on one axis,
     * confident on the other — and fail a `--confident-only` gate on the
     * strength of an improvement.
     *
     * The asymmetry between the axes is deliberate. A pass rate is a count of
     * independent trials, so any drop there is a change in outcome and is
     * reported, with confidence deciding whether it is provable. A mean score
     * is a continuous aggregate that drifts with any judge, so a drop inside
     * the resolution is stable rather than an unprovable regression: flagging
     * every third decimal place would bury the rows that actually broke.
     *
     * @return array{0: string, 1: bool}
     */
    private function verdict(?float $passRateDelta, ?float $scoreDelta, float $resolution): array
    {
        if ($passRateDelta !== null && $passRateDelta < 0.0) {
            return [RowComparison::STATUS_REGRESSED, abs($passRateDelta) > $resolution];
        }

        if ($scoreDelta !== null && $scoreDelta < -$resolution) {
            // Beyond the resolution by construction — that is what made it a
            // regression rather than drift.
            return [RowComparison::STATUS_REGRESSED, true];
        }

        if ($passRateDelta !== null && $passRateDelta > 0.0) {
            return [RowComparison::STATUS_IMPROVED, abs($passRateDelta) > $resolution];
        }

        if ($scoreDelta !== null && $scoreDelta > $resolution) {
            return [RowComparison::STATUS_IMPROVED, true];
        }

        return [RowComparison::STATUS_STABLE, false];
    }

    /**
     * The tolerance, taken from the run's own precision block.
     *
     * Recomputing it from the pooled pass rate would disagree with the number
     * the report prints, and disagree in the direction that matters: on a
     * deterministic suite where half the rows always pass and half always fail,
     * the pooled rate is 0.5 and implies substantial noise, while the report
     * correctly records zero within-row variance. A gate reading the pooled
     * figure would classify a real row regression as unprovable and let it
     * through `--confident-only`.
     *
     * The fallbacks descend: the resolution the report computed, then its
     * observed within-row variance, then — only for an artifact written before
     * this existed — the pooled pass rate.
     *
     * @param  array<string, mixed>  $report
     */
    private function resolutionFor(array $report): float
    {
        $repetitions = $this->int($report, 'repetitions') ?? 1;
        $precision = $report['precision'] ?? null;

        if (is_array($precision)) {
            $resolution = $this->float($precision, 'resolution');

            if ($resolution !== null) {
                return $resolution;
            }

            $variance = $this->float($precision, 'within_row_variance');

            if ($variance !== null) {
                return SamplingPrecision::differenceResolutionFromVariance($variance, $repetitions);
            }
        }

        return SamplingPrecision::differenceResolution($this->float($report, 'pass_rate') ?? 0.0, $repetitions);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, array{sample_id: string, pass_rate: float|null, score_mean: float|null, score_stddev: float|null, repetitions: int}>
     */
    private function rowsBy(array $report, string $joinKey): array
    {
        $rows = [];

        foreach ($this->aggregatesOf($report) as $aggregate) {
            $key = $aggregate[$joinKey] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }

            $rows[$key] = [
                'sample_id' => is_string($aggregate['id'] ?? null) ? $aggregate['id'] : '',
                'pass_rate' => $this->float($aggregate, 'pass_rate'),
                'score_mean' => $this->float($aggregate, 'score_mean'),
                'score_stddev' => $this->float($aggregate, 'score_stddev'),
                'repetitions' => $this->int($aggregate, 'repetitions') ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * @param  array{sample_id: string, pass_rate: float|null, score_mean: float|null, score_stddev: float|null, repetitions: int}|null  $before
     * @param  array{sample_id: string, pass_rate: float|null, score_mean: float|null, score_stddev: float|null, repetitions: int}|null  $after
     */
    private function row(
        string $hash,
        string $sampleId,
        string $status,
        ?array $before,
        ?array $after,
        ?float $passRateDelta,
        ?float $scoreDelta,
        bool $confident,
        float $resolution,
    ): RowComparison {
        return new RowComparison(
            rowHash: $hash,
            sampleId: $sampleId,
            status: $status,
            before: $before === null ? null : $this->stats($before),
            after: $after === null ? null : $this->stats($after),
            passRateDelta: $passRateDelta,
            scoreDelta: $scoreDelta,
            confident: $confident,
            resolution: $resolution,
        );
    }

    /**
     * @param  array{sample_id: string, pass_rate: float|null, score_mean: float|null, score_stddev: float|null, repetitions: int}  $row
     * @return array{pass_rate: float|null, score_mean: float|null, score_stddev: float|null, repetitions: int}
     */
    private function stats(array $row): array
    {
        return [
            'pass_rate' => $row['pass_rate'],
            'score_mean' => $row['score_mean'],
            'score_stddev' => $row['score_stddev'],
            'repetitions' => $row['repetitions'],
        ];
    }

    private function delta(?float $before, ?float $after): ?float
    {
        if ($before === null || $after === null) {
            return null;
        }

        return $after - $before;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function float(array $payload, string $key): ?float
    {
        $value = $payload[$key] ?? null;

        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function int(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
