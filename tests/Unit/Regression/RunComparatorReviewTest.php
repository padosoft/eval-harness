<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Regression;

use Padosoft\EvalHarness\Regression\RowComparison;
use Padosoft\EvalHarness\Regression\RunComparator;
use Padosoft\EvalHarness\Statistics\SamplingPrecision;
use PHPUnit\Framework\TestCase;

/**
 * The failure modes a first review round found in the comparator. Each of these
 * ended in a gate that passed when it should not have, which is the only kind
 * of bug a regression gate cannot afford.
 */
final class RunComparatorReviewTest extends TestCase
{
    /**
     * Recomputing the tolerance from the pooled pass rate disagrees with the
     * number the report prints, in the direction that lets regressions through:
     * a deterministic suite where half the rows always pass pools to p=0.5 and
     * implies noise the run does not have.
     */
    public function test_the_tolerance_comes_from_the_reports_own_precision(): void
    {
        $current = $this->report(
            [$this->row('h1', 'row', passRate: 1.0, scoreMean: 0.60)],
            passRate: 0.5,
            precision: ['resolution' => 0.02, 'within_row_variance' => 0.0],
        );
        $reference = $this->report(
            [$this->row('h1', 'row', passRate: 1.0, scoreMean: 0.95)],
            passRate: 0.5,
            precision: ['resolution' => 0.02, 'within_row_variance' => 0.0],
        );

        $comparison = (new RunComparator)->compare($current, $reference);

        $this->assertSame(0.02, $comparison->resolution);
        $this->assertCount(1, $comparison->regressed());
        $this->assertCount(
            1,
            $comparison->confidentRegressions(),
            'a 35-point score drop is provable at a 2-point resolution',
        );
    }

    public function test_the_within_row_variance_is_used_when_no_resolution_was_recorded(): void
    {
        $payload = $this->report(
            [$this->row('h1', 'row', 1.0, 1.0, repetitions: 10)],
            passRate: 0.5,
            precision: ['within_row_variance' => 0.0],
            repetitions: 10,
        );

        $comparison = (new RunComparator)->compare($payload, $payload);

        $this->assertSame(
            SamplingPrecision::differenceResolutionFromVariance(0.0, 10),
            $comparison->resolution,
        );
    }

    public function test_a_report_without_a_precision_block_falls_back_to_the_pass_rate(): void
    {
        $payload = $this->report([$this->row('h1', 'row', 1.0, 1.0, repetitions: 4)], passRate: 0.5, repetitions: 4);

        $comparison = (new RunComparator)->compare($payload, $payload);

        $this->assertSame(SamplingPrecision::differenceResolution(0.5, 4), $comparison->resolution);
    }

    /**
     * Confidence judged independently of the verdict lets an *improvement* on
     * one axis certify a regression on the other, and fail a --confident-only
     * gate on the strength of good news.
     */
    public function test_confidence_comes_from_the_axis_that_produced_the_verdict(): void
    {
        $current = $this->report(
            [$this->row('h1', 'row', passRate: 0.99, scoreMean: 0.95)],
            precision: ['resolution' => 0.10],
        );
        $reference = $this->report(
            [$this->row('h1', 'row', passRate: 1.0, scoreMean: 0.20)],
            precision: ['resolution' => 0.10],
        );

        $comparison = (new RunComparator)->compare($current, $reference);
        $row = $comparison->rows[0];

        $this->assertSame(RowComparison::STATUS_REGRESSED, $row->status, 'the pass rate dropped');
        $this->assertFalse(
            $row->confident,
            'the drop that made it a regression is inside the resolution; the score improvement must not certify it',
        );
        $this->assertSame([], $comparison->confidentRegressions());
    }

    public function test_a_large_pass_rate_drop_is_still_confident(): void
    {
        $current = $this->report([$this->row('h1', 'row', 0.2, 0.2)], precision: ['resolution' => 0.10]);
        $reference = $this->report([$this->row('h1', 'row', 1.0, 1.0)], precision: ['resolution' => 0.10]);

        $comparison = (new RunComparator)->compare($current, $reference);

        $this->assertCount(1, $comparison->confidentRegressions());
    }

    /**
     * Joining on nothing makes every current row look "added", which reads as
     * zero regressions and passes the gate — the most expensive kind of green.
     */
    public function test_a_reference_without_per_row_data_is_reported_as_incomparable(): void
    {
        $current = $this->report([$this->row('h1', 'row', 1.0, 1.0)]);
        $legacy = ['dataset' => 'rag.factuality', 'macro_f1' => 0.9];

        $comparison = (new RunComparator)->compare($current, $legacy, 'the baseline');

        $this->assertFalse($comparison->isComparable());
        $this->assertStringContainsString('no per-row data', (string) $comparison->incomparableReason);
        $this->assertSame([], $comparison->rows);
        $this->assertNull($comparison->joinKey);
        $this->assertFalse($comparison->toArray()['comparable']);
    }

    /**
     * A degraded join is still a real comparison, and far better than declaring
     * every row new.
     */
    public function test_rows_fall_back_to_joining_on_the_sample_id(): void
    {
        $current = $this->report([['id' => 'row', 'pass_rate' => 0.5, 'score_mean' => 0.5, 'repetitions' => 1]]);
        $reference = $this->report([['id' => 'row', 'pass_rate' => 1.0, 'score_mean' => 1.0, 'repetitions' => 1]]);

        $comparison = (new RunComparator)->compare($current, $reference);

        $this->assertTrue($comparison->isComparable());
        $this->assertTrue($comparison->joinedByIdOnly());
        $this->assertCount(1, $comparison->regressed());
        $this->assertSame('id', $comparison->toArray()['join_key']);
    }

    public function test_hashes_are_preferred_when_both_sides_carry_them(): void
    {
        $current = $this->report([$this->row('h1', 'renamed', 1.0, 1.0)]);
        $reference = $this->report([$this->row('h1', 'original', 1.0, 1.0)]);

        $comparison = (new RunComparator)->compare($current, $reference);

        $this->assertSame('row_hash', $comparison->joinKey);
        $this->assertFalse($comparison->joinedByIdOnly());
        $this->assertSame([], $comparison->added(), 'a rename must not read as a new row');
    }

    /**
     * `before` and `after` are null for added and removed rows, so the
     * newly-failing check has to read them without assuming either exists.
     */
    public function test_added_and_removed_rows_are_not_read_as_newly_failing(): void
    {
        $current = $this->report([$this->row('h-new', 'added-row', 0.0, 0.0)]);
        $reference = $this->report([$this->row('h-old', 'removed-row', 1.0, 1.0)]);

        $comparison = (new RunComparator)->compare($current, $reference);

        foreach ($comparison->rows as $row) {
            $this->assertFalse($row->isNewlyFailing());
        }

        $this->assertCount(1, $comparison->added());
        $this->assertCount(1, $comparison->removed());
    }

    /**
     * @param  list<array<string, mixed>>  $aggregates
     * @param  array<string, mixed>|null  $precision
     * @return array<string, mixed>
     */
    private function report(array $aggregates, float $passRate = 1.0, ?array $precision = null, int $repetitions = 1): array
    {
        $payload = [
            'dataset' => 'rag.factuality',
            'repetitions' => $repetitions,
            'pass_rate' => $passRate,
            'macro_f1' => 0.8,
            'sample_aggregates' => $aggregates,
        ];

        if ($precision !== null) {
            $payload['precision'] = $precision;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $hash, string $id, float $passRate, float $scoreMean, int $repetitions = 1): array
    {
        return [
            'id' => $id,
            'row_hash' => $hash,
            'repetitions' => $repetitions,
            'pass_rate' => $passRate,
            'score_mean' => $scoreMean,
            'score_stddev' => 0.0,
        ];
    }
}
