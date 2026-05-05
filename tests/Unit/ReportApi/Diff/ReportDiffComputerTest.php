<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi\Diff;

use Padosoft\EvalHarness\ReportApi\Diff\ReportDiffComputer;
use Padosoft\EvalHarness\ReportApi\Diff\ReportDiffSchemaMismatchException;
use Padosoft\EvalHarness\Reports\ReportSchema;
use Padosoft\EvalHarness\Tests\TestCase;

final class ReportDiffComputerTest extends TestCase
{
    private ReportDiffComputer $computer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->computer = new ReportDiffComputer;
    }

    public function test_identical_reports_produce_zero_deltas(): void
    {
        $report = $this->minimalReport();

        $diff = $this->computer->compute($report, $report);

        $this->assertSame(0.0, $diff['delta']['macro_f1']);
        $this->assertSame(0, $diff['delta']['total_samples']);
        $this->assertSame(0, $diff['delta']['total_failures']);
        $this->assertSame(0.0, $diff['delta']['duration_seconds']);
        $this->assertSame([], $diff['delta']['cohorts']);
        $this->assertNull($diff['delta']['adversarial']);
    }

    public function test_signed_macro_f1_delta_when_right_lower(): void
    {
        $left = $this->minimalReport(['macro_f1' => 0.85]);
        $right = $this->minimalReport(['macro_f1' => 0.82]);

        $diff = $this->computer->compute($left, $right);

        $this->assertEqualsWithDelta(-0.03, $diff['delta']['macro_f1'], 0.0001);
        $this->assertSame(0.85, $diff['left']['summary']['macro_f1']);
        $this->assertSame(0.82, $diff['right']['summary']['macro_f1']);
    }

    public function test_signed_macro_f1_delta_when_right_higher(): void
    {
        $left = $this->minimalReport(['macro_f1' => 0.70]);
        $right = $this->minimalReport(['macro_f1' => 0.78]);

        $diff = $this->computer->compute($left, $right);

        $this->assertEqualsWithDelta(0.08, $diff['delta']['macro_f1'], 0.0001);
    }

    public function test_per_metric_mean_and_pass_rate_deltas(): void
    {
        $left = $this->minimalReport([
            'metrics' => [
                'exact-match' => ['mean' => 0.7, 'p50' => 1.0, 'p95' => 1.0, 'pass_rate' => 0.7],
                'rouge-l' => ['mean' => 0.5, 'p50' => 0.5, 'p95' => 0.9, 'pass_rate' => 0.4],
            ],
        ]);
        $right = $this->minimalReport([
            'metrics' => [
                'exact-match' => ['mean' => 0.6, 'p50' => 1.0, 'p95' => 1.0, 'pass_rate' => 0.55],
                'rouge-l' => ['mean' => 0.55, 'p50' => 0.6, 'p95' => 0.95, 'pass_rate' => 0.45],
            ],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertEqualsWithDelta(-0.1, $diff['delta']['metrics']['exact-match']['mean'], 0.0001);
        $this->assertEqualsWithDelta(-0.15, $diff['delta']['metrics']['exact-match']['pass_rate'], 0.0001);
        $this->assertEqualsWithDelta(0.05, $diff['delta']['metrics']['rouge-l']['mean'], 0.0001);
        $this->assertEqualsWithDelta(0.05, $diff['delta']['metrics']['rouge-l']['pass_rate'], 0.0001);
    }

    public function test_metric_added_only_on_right_appears_with_full_value(): void
    {
        $left = $this->minimalReport([
            'metrics' => ['exact-match' => ['mean' => 0.5, 'p50' => 0.5, 'p95' => 0.5, 'pass_rate' => 0.5]],
        ]);
        $right = $this->minimalReport([
            'metrics' => [
                'exact-match' => ['mean' => 0.5, 'p50' => 0.5, 'p95' => 0.5, 'pass_rate' => 0.5],
                'rouge-l' => ['mean' => 0.4, 'p50' => 0.4, 'p95' => 0.6, 'pass_rate' => 0.3],
            ],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertSame(0.4, $diff['delta']['metrics']['rouge-l']['mean']);
        $this->assertSame(0.3, $diff['delta']['metrics']['rouge-l']['pass_rate']);
    }

    public function test_cohort_present_on_both_sides_with_pass_rate_drop_is_regressed(): void
    {
        $left = $this->minimalReport([
            'cohorts' => [[
                'name' => 'fy26-q1', 'label' => 'fy26-q1', 'is_untagged' => false, 'sample_count' => 10,
                'metrics' => ['exact-match' => ['mean' => 0.9, 'p50' => 1.0, 'p95' => 1.0, 'pass_rate' => 0.9]],
            ]],
        ]);
        $right = $this->minimalReport([
            'cohorts' => [[
                'name' => 'fy26-q1', 'label' => 'fy26-q1', 'is_untagged' => false, 'sample_count' => 10,
                'metrics' => ['exact-match' => ['mean' => 0.7, 'p50' => 1.0, 'p95' => 1.0, 'pass_rate' => 0.6]],
            ]],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertCount(1, $diff['delta']['cohorts']);
        $this->assertSame('fy26-q1', $diff['delta']['cohorts'][0]['tag']);
        $this->assertSame('regressed', $diff['delta']['cohorts'][0]['status']);
        $this->assertEqualsWithDelta(-0.3, $diff['delta']['cohorts'][0]['metrics']['exact-match']['pass_rate'], 0.0001);
    }

    public function test_cohort_with_pass_rate_increase_is_improved(): void
    {
        $left = $this->minimalReport([
            'cohorts' => [[
                'name' => 'fy26-q1', 'label' => 'fy26-q1', 'is_untagged' => false, 'sample_count' => 10,
                'metrics' => ['exact-match' => ['mean' => 0.7, 'p50' => 0.7, 'p95' => 0.7, 'pass_rate' => 0.5]],
            ]],
        ]);
        $right = $this->minimalReport([
            'cohorts' => [[
                'name' => 'fy26-q1', 'label' => 'fy26-q1', 'is_untagged' => false, 'sample_count' => 10,
                'metrics' => ['exact-match' => ['mean' => 0.85, 'p50' => 0.85, 'p95' => 0.85, 'pass_rate' => 0.75]],
            ]],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertSame('improved', $diff['delta']['cohorts'][0]['status']);
    }

    public function test_cohort_added_only_on_right_has_added_status(): void
    {
        $left = $this->minimalReport(['cohorts' => []]);
        $right = $this->minimalReport([
            'cohorts' => [[
                'name' => 'new-cohort', 'label' => 'new-cohort', 'is_untagged' => false, 'sample_count' => 5,
                'metrics' => ['exact-match' => ['mean' => 0.5, 'p50' => 0.5, 'p95' => 0.5, 'pass_rate' => 0.5]],
            ]],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertCount(1, $diff['delta']['cohorts']);
        $this->assertSame('new-cohort', $diff['delta']['cohorts'][0]['tag']);
        $this->assertSame('added', $diff['delta']['cohorts'][0]['status']);
    }

    public function test_cohort_removed_only_on_left_has_removed_status(): void
    {
        $left = $this->minimalReport([
            'cohorts' => [[
                'name' => 'old-cohort', 'label' => 'old-cohort', 'is_untagged' => false, 'sample_count' => 5,
                'metrics' => ['exact-match' => ['mean' => 0.5, 'p50' => 0.5, 'p95' => 0.5, 'pass_rate' => 0.5]],
            ]],
        ]);
        $right = $this->minimalReport(['cohorts' => []]);

        $diff = $this->computer->compute($left, $right);

        $this->assertCount(1, $diff['delta']['cohorts']);
        $this->assertSame('removed', $diff['delta']['cohorts'][0]['status']);
    }

    public function test_untagged_cohorts_match_across_sides(): void
    {
        $left = $this->minimalReport([
            'cohorts' => [[
                'name' => null, 'label' => '(untagged)', 'is_untagged' => true, 'sample_count' => 3,
                'metrics' => ['exact-match' => ['mean' => 0.6, 'p50' => 0.6, 'p95' => 0.6, 'pass_rate' => 0.6]],
            ]],
        ]);
        $right = $this->minimalReport([
            'cohorts' => [[
                'name' => null, 'label' => '(untagged)', 'is_untagged' => true, 'sample_count' => 3,
                'metrics' => ['exact-match' => ['mean' => 0.4, 'p50' => 0.4, 'p95' => 0.4, 'pass_rate' => 0.3]],
            ]],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertCount(1, $diff['delta']['cohorts']);
        $this->assertSame('__untagged__', $diff['delta']['cohorts'][0]['tag']);
        $this->assertSame('regressed', $diff['delta']['cohorts'][0]['status']);
    }

    public function test_adversarial_block_returns_null_when_either_side_missing(): void
    {
        $left = $this->minimalReport(['adversarial' => null]);
        $right = $this->minimalReport([
            'adversarial' => ['total_samples' => 5, 'categories' => []],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertNull($diff['delta']['adversarial']);
    }

    public function test_adversarial_categories_added_removed_and_regressed(): void
    {
        $left = $this->minimalReport([
            'adversarial' => [
                'total_samples' => 8,
                'categories' => [
                    ['category' => 'prompt-injection', 'sample_count' => 4,
                        'metrics' => ['refusal-quality' => ['mean' => 0.9, 'pass_rate' => 0.85]]],
                    ['category' => 'jailbreak', 'sample_count' => 4,
                        'metrics' => ['refusal-quality' => ['mean' => 0.8, 'pass_rate' => 0.75]]],
                ],
            ],
        ]);
        $right = $this->minimalReport([
            'adversarial' => [
                'total_samples' => 12,
                'categories' => [
                    ['category' => 'prompt-injection', 'sample_count' => 4,
                        'metrics' => ['refusal-quality' => ['mean' => 0.7, 'pass_rate' => 0.6]]],
                    ['category' => 'data-leak', 'sample_count' => 8,
                        'metrics' => ['refusal-quality' => ['mean' => 0.95, 'pass_rate' => 0.9]]],
                ],
            ],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertNotNull($diff['delta']['adversarial']);
        $this->assertSame(4, $diff['delta']['adversarial']['total_samples']);

        $byCategory = [];
        foreach ($diff['delta']['adversarial']['categories'] as $row) {
            $byCategory[$row['category']] = $row;
        }

        $this->assertSame('regressed', $byCategory['prompt-injection']['status']);
        $this->assertSame(0, $byCategory['prompt-injection']['sample_count']);

        $this->assertSame('removed', $byCategory['jailbreak']['status']);
        $this->assertSame(-4, $byCategory['jailbreak']['sample_count']);

        $this->assertSame('added', $byCategory['data-leak']['status']);
        $this->assertSame(8, $byCategory['data-leak']['sample_count']);
    }

    public function test_total_samples_and_failures_deltas(): void
    {
        $left = $this->minimalReport(['total_samples' => 200, 'total_failures' => 5]);
        $right = $this->minimalReport(['total_samples' => 220, 'total_failures' => 12]);

        $diff = $this->computer->compute($left, $right);

        $this->assertSame(20, $diff['delta']['total_samples']);
        $this->assertSame(7, $diff['delta']['total_failures']);
    }

    public function test_left_schema_version_mismatch_throws(): void
    {
        $this->expectException(ReportDiffSchemaMismatchException::class);
        $this->expectExceptionMessage('left side');

        $this->computer->compute(
            $this->minimalReport(['schema_version' => 'eval-harness.report.v0']),
            $this->minimalReport(),
        );
    }

    public function test_right_schema_version_mismatch_throws(): void
    {
        $this->expectException(ReportDiffSchemaMismatchException::class);
        $this->expectExceptionMessage('right side');

        $this->computer->compute(
            $this->minimalReport(),
            $this->minimalReport(['schema_version' => 'something-else']),
        );
    }

    public function test_missing_metrics_block_treated_as_empty_not_fatal(): void
    {
        $left = $this->minimalReport();
        unset($left['metrics']);
        $right = $this->minimalReport([
            'metrics' => ['exact-match' => ['mean' => 0.5, 'p50' => 0.5, 'p95' => 0.5, 'pass_rate' => 0.5]],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertSame(0.5, $diff['delta']['metrics']['exact-match']['mean']);
    }

    public function test_literal_double_underscore_untagged_tag_does_not_collide_with_synthetic_untagged_bucket(): void
    {
        // Regression for Copilot review on PR #40 (commit 59b6b8e).
        // The old impl used the bare string '__untagged__' as both
        // the synthetic untagged bucket key AND a possible real tag
        // value, so a dataset carrying a literal `__untagged__` tag
        // plus an untagged sample collapsed two cohorts into one —
        // whichever got iterated last won. Index keys now namespace
        // tagged cohorts under "tag:<name>" and the synthetic untagged
        // bucket under a null-byte sentinel that no real tag string
        // can produce.
        $left = $this->minimalReport([
            'cohorts' => [
                [
                    'name' => '__untagged__',
                    'label' => '__untagged__',
                    'is_untagged' => false,
                    'sample_count' => 5,
                    'metrics' => ['exact-match' => ['mean' => 0.6, 'p50' => 0.6, 'p95' => 0.6, 'pass_rate' => 0.6]],
                ],
                [
                    'name' => null,
                    'label' => '(untagged)',
                    'is_untagged' => true,
                    'sample_count' => 3,
                    'metrics' => ['exact-match' => ['mean' => 0.4, 'p50' => 0.4, 'p95' => 0.4, 'pass_rate' => 0.4]],
                ],
            ],
        ]);
        $right = $this->minimalReport([
            'cohorts' => [
                [
                    'name' => '__untagged__',
                    'label' => '__untagged__',
                    'is_untagged' => false,
                    'sample_count' => 5,
                    'metrics' => ['exact-match' => ['mean' => 0.5, 'p50' => 0.5, 'p95' => 0.5, 'pass_rate' => 0.5]],
                ],
                [
                    'name' => null,
                    'label' => '(untagged)',
                    'is_untagged' => true,
                    'sample_count' => 3,
                    'metrics' => ['exact-match' => ['mean' => 0.45, 'p50' => 0.45, 'p95' => 0.45, 'pass_rate' => 0.45]],
                ],
            ],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertCount(2, $diff['delta']['cohorts']);
        $tags = array_column($diff['delta']['cohorts'], 'tag');
        sort($tags);
        $this->assertSame(['__untagged__', '__untagged__'], $tags);

        $keys = array_column($diff['delta']['cohorts'], 'key');
        sort($keys);
        $this->assertSame(['tag:__untagged__', 'untagged'], $keys);
        $isUntaggedFlags = array_column($diff['delta']['cohorts'], 'is_untagged');
        sort($isUntaggedFlags);
        $this->assertSame([false, true], $isUntaggedFlags);

        // One row corresponds to the literal tag (regressed pass_rate
        // from 0.6 → 0.5); the other to the synthetic untagged bucket
        // (improved pass_rate from 0.4 → 0.45). Both must be present.
        $statuses = array_column($diff['delta']['cohorts'], 'status');
        sort($statuses);
        $this->assertSame(['improved', 'regressed'], $statuses);
    }

    public function test_non_array_metrics_payload_is_tolerated_and_does_not_500(): void
    {
        // Regression for Copilot review on PR #40 (commit 59b6b8e).
        // The old impl passed `$cohort['metrics'] ?? []` straight into
        // `cohortMetricsDelta(array, array)`, which TypeErrored on a
        // non-array (e.g. a string) `metrics` value produced by a
        // partial / migrating report. The class contract advertises
        // tolerance for malformed/mistyped fields, so this case must
        // produce a diff with the right side's deltas instead of
        // throwing.
        $left = $this->minimalReport([
            'cohorts' => [[
                'name' => 'fy26-q1',
                'label' => 'fy26-q1',
                'is_untagged' => false,
                'sample_count' => 10,
                'metrics' => 'malformed-string-instead-of-array',
            ]],
        ]);
        $right = $this->minimalReport([
            'cohorts' => [[
                'name' => 'fy26-q1',
                'label' => 'fy26-q1',
                'is_untagged' => false,
                'sample_count' => 10,
                'metrics' => ['exact-match' => ['mean' => 0.7, 'p50' => 0.7, 'p95' => 0.7, 'pass_rate' => 0.5]],
            ]],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertCount(1, $diff['delta']['cohorts']);
        $this->assertSame('fy26-q1', $diff['delta']['cohorts'][0]['tag']);
        $this->assertSame(0.7, $diff['delta']['cohorts'][0]['metrics']['exact-match']['mean']);
    }

    public function test_non_array_metrics_in_adversarial_category_is_tolerated(): void
    {
        $left = $this->minimalReport([
            'adversarial' => [
                'total_samples' => 4,
                'categories' => [
                    ['category' => 'prompt-injection', 'sample_count' => 4, 'metrics' => 'malformed'],
                ],
            ],
        ]);
        $right = $this->minimalReport([
            'adversarial' => [
                'total_samples' => 4,
                'categories' => [
                    ['category' => 'prompt-injection', 'sample_count' => 4,
                        'metrics' => ['refusal-quality' => ['mean' => 0.8, 'pass_rate' => 0.8]]],
                ],
            ],
        ]);

        $diff = $this->computer->compute($left, $right);

        $this->assertNotNull($diff['delta']['adversarial']);
        $this->assertSame(0.8, $diff['delta']['adversarial']['categories'][0]['metrics']['refusal-quality']['mean']);
    }

    public function test_summary_extracts_dataset_and_timing(): void
    {
        $left = $this->minimalReport([
            'dataset' => 'rag.factuality.fy2026',
            'started_at' => 1730000000.0,
            'finished_at' => 1730001500.5,
            'duration_seconds' => 1500.5,
        ]);

        $diff = $this->computer->compute($left, $left);

        $this->assertSame('rag.factuality.fy2026', $diff['left']['summary']['dataset']);
        $this->assertSame(1730000000.0, $diff['left']['summary']['started_at']);
        $this->assertSame(1730001500.5, $diff['left']['summary']['finished_at']);
        $this->assertSame(1500.5, $diff['left']['summary']['duration_seconds']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function minimalReport(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => ReportSchema::VERSION,
            'dataset' => 'rag.factuality',
            'started_at' => 1730000000.0,
            'finished_at' => 1730000060.0,
            'duration_seconds' => 60.0,
            'total_samples' => 100,
            'total_failures' => 0,
            'macro_f1' => 0.8,
            'metrics' => [],
            'metric_distributions' => [],
            'usage' => [],
            'cohorts' => [],
            'adversarial' => null,
            'samples' => [],
            'failures' => [],
        ], $overrides);
    }
}
