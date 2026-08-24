<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Regression;

use Padosoft\EvalHarness\Regression\RegressionSchema;
use Padosoft\EvalHarness\Regression\RowComparison;
use Padosoft\EvalHarness\Regression\RunComparator;
use PHPUnit\Framework\TestCase;

final class RunComparatorTest extends TestCase
{
    public function test_a_row_that_lost_pass_rate_is_a_regression(): void
    {
        $comparison = $this->compare(
            current: [$this->row('h1', 'refund', passRate: 0.5, scoreMean: 0.5)],
            reference: [$this->row('h1', 'refund', passRate: 1.0, scoreMean: 1.0)],
        );

        $this->assertCount(1, $comparison->regressed());
        $this->assertSame('refund', $comparison->regressed()[0]->sampleId);
        $this->assertEqualsWithDelta(-0.5, $comparison->regressed()[0]->passRateDelta, 1e-9);
    }

    public function test_a_row_that_gained_pass_rate_is_an_improvement(): void
    {
        $comparison = $this->compare(
            current: [$this->row('h1', 'refund', passRate: 1.0, scoreMean: 1.0)],
            reference: [$this->row('h1', 'refund', passRate: 0.5, scoreMean: 0.5)],
        );

        $this->assertCount(1, $comparison->improved());
        $this->assertSame([], $comparison->regressed());
    }

    public function test_rows_join_on_the_hash_not_the_id(): void
    {
        $comparison = $this->compare(
            current: [$this->row('h1', 'renamed-row', passRate: 1.0, scoreMean: 1.0)],
            reference: [$this->row('h1', 'old-name', passRate: 1.0, scoreMean: 1.0)],
        );

        $this->assertSame([], $comparison->added());
        $this->assertSame([], $comparison->removed());
        $this->assertCount(1, $comparison->rows);
        $this->assertSame(RowComparison::STATUS_STABLE, $comparison->rows[0]->status);
    }

    public function test_rows_present_on_only_one_side_are_added_or_removed(): void
    {
        $comparison = $this->compare(
            current: [$this->row('h1', 'kept', 1.0, 1.0), $this->row('h2', 'new', 1.0, 1.0)],
            reference: [$this->row('h1', 'kept', 1.0, 1.0), $this->row('h3', 'gone', 1.0, 1.0)],
        );

        $this->assertCount(1, $comparison->added());
        $this->assertSame('new', $comparison->added()[0]->sampleId);
        $this->assertCount(1, $comparison->removed());
        $this->assertSame('gone', $comparison->removed()[0]->sampleId);
    }

    /**
     * A score that drifted inside the run's own detectable difference is not a
     * regression: reporting every third decimal place buries the rows that
     * actually broke.
     */
    public function test_a_score_drift_inside_the_resolution_is_stable(): void
    {
        $comparison = $this->compare(
            current: [$this->row('h1', 'row', passRate: 1.0, scoreMean: 0.90)],
            reference: [$this->row('h1', 'row', passRate: 1.0, scoreMean: 0.92)],
            repetitions: 10,
            passRate: 1.0,
        );

        // Rule of three at 10 repetitions: resolution is 0.30, so a 0.02 drift
        // is well inside it.
        $this->assertSame([], $comparison->regressed());
        $this->assertSame(RowComparison::STATUS_STABLE, $comparison->rows[0]->status);
    }

    public function test_a_score_drop_beyond_the_resolution_is_a_regression(): void
    {
        $comparison = $this->compare(
            current: [$this->row('h1', 'row', passRate: 1.0, scoreMean: 0.20)],
            reference: [$this->row('h1', 'row', passRate: 1.0, scoreMean: 0.95)],
            repetitions: 10,
            passRate: 1.0,
        );

        $this->assertCount(1, $comparison->regressed());
        $this->assertTrue($comparison->regressed()[0]->confident);
    }

    /**
     * The distinction the whole design turns on: a single-execution run can see
     * that a row broke, and cannot prove it. Both facts are reported.
     */
    public function test_a_regression_on_one_repetition_is_reported_but_not_confident(): void
    {
        $comparison = $this->compare(
            current: [$this->row('h1', 'row', passRate: 0.0, scoreMean: 0.0, repetitions: 1)],
            reference: [$this->row('h1', 'row', passRate: 1.0, scoreMean: 1.0, repetitions: 1)],
            repetitions: 1,
            passRate: 0.0,
        );

        $this->assertCount(1, $comparison->regressed());
        $this->assertFalse($comparison->regressed()[0]->confident);
        $this->assertSame([], $comparison->confidentRegressions());
        $this->assertSame(1.0, $comparison->resolution);
    }

    public function test_a_newly_failing_row_is_flagged(): void
    {
        $comparison = $this->compare(
            current: [$this->row('h1', 'row', passRate: 0.6, scoreMean: 0.6, repetitions: 5)],
            reference: [$this->row('h1', 'row', passRate: 1.0, scoreMean: 1.0, repetitions: 5)],
            repetitions: 5,
        );

        $this->assertCount(1, $comparison->newlyFailing());
        $this->assertTrue($comparison->regressed()[0]->isNewlyFailing());
    }

    public function test_a_row_that_was_already_failing_is_not_newly_failing(): void
    {
        $comparison = $this->compare(
            current: [$this->row('h1', 'row', passRate: 0.2, scoreMean: 0.2, repetitions: 5)],
            reference: [$this->row('h1', 'row', passRate: 0.6, scoreMean: 0.6, repetitions: 5)],
            repetitions: 5,
        );

        $this->assertCount(1, $comparison->regressed());
        $this->assertSame([], $comparison->newlyFailing());
    }

    public function test_an_explicit_epsilon_replaces_the_statistical_resolution(): void
    {
        $comparison = $this->compare(
            current: [$this->row('h1', 'row', passRate: 1.0, scoreMean: 0.80)],
            reference: [$this->row('h1', 'row', passRate: 1.0, scoreMean: 0.95)],
            repetitions: 10,
            passRate: 1.0,
            epsilon: 0.05,
        );

        $this->assertSame(0.05, $comparison->resolution);
        $this->assertFalse($comparison->resolutionIsStatistical);
        $this->assertCount(1, $comparison->regressed());
    }

    public function test_resolution_tightens_as_repetitions_grow(): void
    {
        $few = $this->compare(
            current: [$this->row('h1', 'row', 0.5, 0.5, 3)],
            reference: [$this->row('h1', 'row', 0.5, 0.5, 3)],
            repetitions: 3,
            passRate: 0.5,
        );

        $many = $this->compare(
            current: [$this->row('h1', 'row', 0.5, 0.5, 100)],
            reference: [$this->row('h1', 'row', 0.5, 0.5, 100)],
            repetitions: 100,
            passRate: 0.5,
        );

        $this->assertGreaterThan($many->resolution, $few->resolution);
        $this->assertTrue($few->resolutionIsStatistical);
    }

    public function test_payload_is_versioned_and_counts_both_kinds_of_regression(): void
    {
        $comparison = $this->compare(
            current: [$this->row('h1', 'row', passRate: 0.0, scoreMean: 0.0, repetitions: 1)],
            reference: [$this->row('h1', 'row', passRate: 1.0, scoreMean: 1.0, repetitions: 1)],
            repetitions: 1,
            passRate: 0.0,
        );

        $payload = $comparison->toArray();

        $this->assertSame(RegressionSchema::VERSION, $payload['schema_version']);
        $this->assertSame(1, $payload['counts']['regressed']);
        $this->assertSame(0, $payload['counts']['regressed_confident']);
        $this->assertSame(1, $payload['counts']['compared']);
        $this->assertFalse($payload['rows'][0]['confident']);
    }

    public function test_a_report_without_aggregates_compares_to_nothing(): void
    {
        $comparator = new RunComparator;
        $comparison = $comparator->compare(['dataset' => 'x'], ['dataset' => 'x']);

        $this->assertSame([], $comparison->rows);
        $this->assertFalse($comparison->hasChanges());
    }

    public function test_malformed_aggregate_entries_are_skipped(): void
    {
        $comparator = new RunComparator;
        $comparison = $comparator->compare(
            ['dataset' => 'x', 'repetitions' => 1, 'pass_rate' => 1.0, 'sample_aggregates' => ['nonsense', ['id' => 'no-hash']]],
            ['dataset' => 'x', 'repetitions' => 1, 'pass_rate' => 1.0, 'sample_aggregates' => []],
        );

        $this->assertSame([], $comparison->rows);
    }

    /**
     * @param  list<array<string, mixed>>  $current
     * @param  list<array<string, mixed>>  $reference
     */
    private function compare(
        array $current,
        array $reference,
        int $repetitions = 1,
        float $passRate = 1.0,
        ?float $epsilon = null,
    ) {
        return (new RunComparator)->compare(
            current: $this->report($current, $repetitions, $passRate),
            reference: $this->report($reference, $repetitions, $passRate),
            referenceLabel: 'the baseline',
            epsilon: $epsilon,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $aggregates
     * @return array<string, mixed>
     */
    private function report(array $aggregates, int $repetitions, float $passRate): array
    {
        return [
            'dataset' => 'rag.factuality',
            'repetitions' => $repetitions,
            'pass_rate' => $passRate,
            'macro_f1' => 0.8,
            'sample_aggregates' => $aggregates,
        ];
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
