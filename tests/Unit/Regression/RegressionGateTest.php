<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Regression;

use Padosoft\EvalHarness\Regression\RegressionGate;
use Padosoft\EvalHarness\Regression\RowComparison;
use Padosoft\EvalHarness\Regression\RunComparison;
use PHPUnit\Framework\TestCase;

final class RegressionGateTest extends TestCase
{
    public function test_a_clean_comparison_passes(): void
    {
        $verdict = (new RegressionGate)->evaluate($this->comparison([]));

        $this->assertTrue($verdict['passed']);
        $this->assertSame([], $verdict['failures']);
        $this->assertSame(0, $verdict['counted_regressions']);
    }

    public function test_one_regression_fails_the_default_gate(): void
    {
        $verdict = (new RegressionGate)->evaluate($this->comparison([$this->regression(confident: true)]));

        $this->assertFalse($verdict['passed']);
        $this->assertStringContainsString('1 row regressed', $verdict['failures'][0]);
        $this->assertStringContainsString('the baseline', $verdict['failures'][0]);
    }

    public function test_regressions_within_the_allowance_pass(): void
    {
        $verdict = (new RegressionGate(maxRegressions: 2))
            ->evaluate($this->comparison([$this->regression(true), $this->regression(true)]));

        $this->assertTrue($verdict['passed']);
        $this->assertSame(2, $verdict['counted_regressions']);
    }

    /**
     * The default counts every regression, because a row that went red on a
     * single-execution run is still a break worth stopping a PR for.
     */
    public function test_unprovable_regressions_still_count_by_default(): void
    {
        $verdict = (new RegressionGate)->evaluate($this->comparison([$this->regression(confident: false)]));

        $this->assertFalse($verdict['passed']);
        $this->assertSame(1, $verdict['counted_regressions']);
    }

    public function test_confident_only_ignores_regressions_the_run_could_not_prove(): void
    {
        $verdict = (new RegressionGate(confidentOnly: true))
            ->evaluate($this->comparison([$this->regression(confident: false)]));

        $this->assertTrue($verdict['passed']);
        $this->assertSame(0, $verdict['counted_regressions']);
    }

    public function test_confident_only_still_fails_on_a_provable_regression(): void
    {
        $verdict = (new RegressionGate(confidentOnly: true))
            ->evaluate($this->comparison([$this->regression(confident: true)]));

        $this->assertFalse($verdict['passed']);
        $this->assertStringContainsString('confident row regressed', $verdict['failures'][0]);
    }

    /**
     * A gate that fails on rows it could not prove has to say so, or it loses
     * the audience it needs the next time it is right.
     */
    public function test_the_failure_message_reports_how_many_were_beyond_noise(): void
    {
        $verdict = (new RegressionGate)->evaluate($this->comparison([
            $this->regression(confident: true),
            $this->regression(confident: false),
            $this->regression(confident: false),
        ]));

        $this->assertStringContainsString('1 of 3 exceed', $verdict['failures'][0]);
        $this->assertStringContainsString('detectable difference', $verdict['failures'][0]);
    }

    public function test_the_note_is_omitted_when_every_regression_is_provable(): void
    {
        $verdict = (new RegressionGate)->evaluate($this->comparison([$this->regression(true), $this->regression(true)]));

        $this->assertStringNotContainsString('exceed', $verdict['failures'][0]);
    }

    public function test_absolute_thresholds_are_enforced_against_the_current_report(): void
    {
        $gate = new RegressionGate(maxRegressions: 10, minMacroF1: 0.9, minPassRate: 0.95);

        $verdict = $gate->evaluate($this->comparison([]), ['macro_f1' => 0.4, 'pass_rate' => 0.5]);

        $this->assertFalse($verdict['passed']);
        $this->assertCount(2, $verdict['failures']);
        $this->assertStringContainsString('macro-F1 0.4000 is below', $verdict['failures'][0]);
        $this->assertStringContainsString('pass rate 0.5000 is below', $verdict['failures'][1]);
    }

    public function test_a_missing_metric_fails_a_threshold_rather_than_passing_it(): void
    {
        $verdict = (new RegressionGate(minMacroF1: 0.5))->evaluate($this->comparison([]), []);

        $this->assertFalse($verdict['passed']);
        $this->assertStringContainsString('macro-F1 n/a is below', $verdict['failures'][0]);
    }

    /**
     * @param  list<RowComparison>  $rows
     */
    private function comparison(array $rows): RunComparison
    {
        return new RunComparison(
            dataset: 'rag',
            referenceLabel: 'the baseline',
            rows: $rows,
            macroF1Delta: null,
            passRateDelta: null,
            resolution: 0.25,
            resolutionIsStatistical: true,
        );
    }

    private function regression(bool $confident): RowComparison
    {
        return new RowComparison(
            rowHash: bin2hex(random_bytes(8)),
            sampleId: 'row',
            status: RowComparison::STATUS_REGRESSED,
            before: ['pass_rate' => 1.0, 'score_mean' => 1.0, 'score_stddev' => 0.0, 'repetitions' => 3],
            after: ['pass_rate' => 0.3, 'score_mean' => 0.3, 'score_stddev' => 0.1, 'repetitions' => 3],
            passRateDelta: -0.7,
            scoreDelta: -0.7,
            confident: $confident,
            resolution: 0.25,
        );
    }
}
