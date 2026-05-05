<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Diff;

use Padosoft\EvalHarness\Reports\ReportSchema;

/**
 * Pure-logic diff between two decoded JSON eval-harness reports.
 *
 * Both reports must share `schema_version === ReportSchema::VERSION`.
 * Missing / mistyped fields are tolerated — the diff returns whatever
 * fields are present on both sides and skips the rest, so a partial
 * report still produces a useful payload instead of a 500.
 *
 * Returned shape (top-level):
 *
 * ```
 * [
 *   'left'  => ['summary' => [...]],
 *   'right' => ['summary' => [...]],
 *   'delta' => [
 *     'macro_f1' => float (right - left),
 *     'total_samples' => int,
 *     'total_failures' => int,
 *     'duration_seconds' => float,
 *     'metrics' => [<metric> => ['mean' => float, 'pass_rate' => float]],
 *     'cohorts' => [
 *       ['key' => string, 'tag' => string, 'is_untagged' => bool,
 *        'status' => 'added|removed|regressed|improved|stable',
 *        'metrics' => [<metric> => ['mean' => float, 'pass_rate' => float]]],
 *     ],
 *     'adversarial' => [
 *       'total_samples' => int,
 *       'categories' => [
 *         ['category' => string, 'status' => '...',
 *          'sample_count' => int,
 *          'metrics' => [<metric> => ['mean' => float, 'pass_rate' => float]]],
 *       ],
 *     ] | null,
 *   ],
 * ]
 * ```
 */
final class ReportDiffComputer
{
    private const REGRESSION_EPSILON = 0.0001;

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array{
     *     left: array{summary: array<string, mixed>},
     *     right: array{summary: array<string, mixed>},
     *     delta: array{
     *         macro_f1: float,
     *         total_samples: int,
     *         total_failures: int,
     *         duration_seconds: float,
     *         metrics: array<string, array{mean: float, pass_rate: float}>,
     *         cohorts: list<array{key: string, tag: string, is_untagged: bool, status: string, metrics: array<string, array{mean: float, pass_rate: float}>}>,
     *         adversarial: array{total_samples: int, categories: list<array<string, mixed>>}|null
     *     }
     * }
     */
    public function compute(array $left, array $right): array
    {
        $this->assertSchemaVersion($left, 'left');
        $this->assertSchemaVersion($right, 'right');

        return [
            'left' => ['summary' => $this->summary($left)],
            'right' => ['summary' => $this->summary($right)],
            'delta' => [
                'macro_f1' => $this->floatDelta($right, $left, 'macro_f1'),
                'total_samples' => $this->intDelta($right, $left, 'total_samples'),
                'total_failures' => $this->intDelta($right, $left, 'total_failures'),
                'duration_seconds' => $this->floatDelta($right, $left, 'duration_seconds'),
                'metrics' => $this->metricsDelta($left, $right),
                'cohorts' => $this->cohortsDelta($left, $right),
                'adversarial' => $this->adversarialDelta($left, $right),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function assertSchemaVersion(array $report, string $side): void
    {
        $version = $report['schema_version'] ?? null;
        if ($version !== ReportSchema::VERSION) {
            throw new ReportDiffSchemaMismatchException(sprintf(
                "Report on %s side has schema_version '%s'; expected '%s'.",
                $side,
                $this->describeSchemaVersion($version),
                ReportSchema::VERSION,
            ));
        }
    }

    private function describeSchemaVersion(mixed $version): string
    {
        if (is_string($version)) {
            return $version;
        }

        if ($version === null) {
            return 'null';
        }

        if (is_scalar($version)) {
            return get_debug_type($version).'('.(string) $version.')';
        }

        try {
            return get_debug_type($version).'('.json_encode($version, JSON_THROW_ON_ERROR).')';
        } catch (\JsonException) {
            return get_debug_type($version);
        }
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function summary(array $report): array
    {
        return [
            'dataset' => $this->stringOrNull($report, 'dataset'),
            'schema_version' => $this->stringOrNull($report, 'schema_version'),
            'started_at' => $this->floatOrNull($report, 'started_at'),
            'finished_at' => $this->floatOrNull($report, 'finished_at'),
            'duration_seconds' => $this->floatOrZero($report, 'duration_seconds'),
            'total_samples' => $this->intOrZero($report, 'total_samples'),
            'total_failures' => $this->intOrZero($report, 'total_failures'),
            'macro_f1' => $this->floatOrZero($report, 'macro_f1'),
        ];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array<string, array{mean: float, pass_rate: float}>
     */
    private function metricsDelta(array $left, array $right): array
    {
        $leftMetrics = $this->metricBlock($left);
        $rightMetrics = $this->metricBlock($right);

        $metricNames = array_keys($leftMetrics + $rightMetrics);
        sort($metricNames);

        $delta = [];
        foreach ($metricNames as $name) {
            $delta[$name] = $this->aggregateDelta($leftMetrics[$name] ?? [], $rightMetrics[$name] ?? []);
        }

        return $delta;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return list<array{key: string, tag: string, is_untagged: bool, status: string, metrics: array<string, array{mean: float, pass_rate: float}>}>
     */
    private function cohortsDelta(array $left, array $right): array
    {
        $leftCohorts = $this->cohortIndex($left);
        $rightCohorts = $this->cohortIndex($right);

        $keys = array_keys($leftCohorts + $rightCohorts);
        sort($keys);

        $rows = [];
        foreach ($keys as $key) {
            $leftCohort = $leftCohorts[$key] ?? null;
            $rightCohort = $rightCohorts[$key] ?? null;

            $present = $rightCohort ?? $leftCohort;
            if (! is_array($present)) {
                continue;
            }

            if ($leftCohort === null && $rightCohort !== null) {
                $rows[] = [
                    'key' => $this->cohortPublicKey($present),
                    'tag' => $this->cohortDisplayTag($present),
                    'is_untagged' => $this->cohortIsUntagged($present),
                    'status' => 'added',
                    'metrics' => $this->cohortMetricsDelta([], $this->metricsBlock($rightCohort)),
                ];

                continue;
            }

            if ($rightCohort === null && $leftCohort !== null) {
                $rows[] = [
                    'key' => $this->cohortPublicKey($present),
                    'tag' => $this->cohortDisplayTag($present),
                    'is_untagged' => $this->cohortIsUntagged($present),
                    'status' => 'removed',
                    'metrics' => $this->cohortMetricsDelta($this->metricsBlock($leftCohort), []),
                ];

                continue;
            }

            if ($leftCohort === null || $rightCohort === null) {
                continue;
            }

            $metricsDelta = $this->cohortMetricsDelta(
                $this->metricsBlock($leftCohort),
                $this->metricsBlock($rightCohort),
            );

            $rows[] = [
                'key' => $this->cohortPublicKey($present),
                'tag' => $this->cohortDisplayTag($present),
                'is_untagged' => $this->cohortIsUntagged($present),
                'status' => $this->cohortStatus($metricsDelta),
                'metrics' => $metricsDelta,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array{total_samples: int, categories: list<array<string, mixed>>}|null
     */
    private function adversarialDelta(array $left, array $right): ?array
    {
        $leftBlock = $left['adversarial'] ?? null;
        $rightBlock = $right['adversarial'] ?? null;

        if (! is_array($leftBlock) || ! is_array($rightBlock)) {
            return null;
        }

        $leftCats = $this->adversarialCategoryIndex($leftBlock);
        $rightCats = $this->adversarialCategoryIndex($rightBlock);

        $names = array_keys($leftCats + $rightCats);
        sort($names);

        $categories = [];
        foreach ($names as $name) {
            $leftCat = $leftCats[$name] ?? null;
            $rightCat = $rightCats[$name] ?? null;

            if ($leftCat === null && $rightCat !== null) {
                $categories[] = [
                    'category' => $name,
                    'status' => 'added',
                    'sample_count' => $this->intOrZero($rightCat, 'sample_count'),
                    'metrics' => $this->cohortMetricsDelta([], $this->metricsBlock($rightCat)),
                ];

                continue;
            }

            if ($rightCat === null && $leftCat !== null) {
                $categories[] = [
                    'category' => $name,
                    'status' => 'removed',
                    'sample_count' => -$this->intOrZero($leftCat, 'sample_count'),
                    'metrics' => $this->cohortMetricsDelta($this->metricsBlock($leftCat), []),
                ];

                continue;
            }

            if ($leftCat === null || $rightCat === null) {
                continue;
            }

            $metricsDelta = $this->cohortMetricsDelta(
                $this->metricsBlock($leftCat),
                $this->metricsBlock($rightCat),
            );

            $categories[] = [
                'category' => $name,
                'status' => $this->cohortStatus($metricsDelta),
                'sample_count' => $this->intOrZero($rightCat, 'sample_count') - $this->intOrZero($leftCat, 'sample_count'),
                'metrics' => $metricsDelta,
            ];
        }

        return [
            'total_samples' => $this->intOrZero($rightBlock, 'total_samples') - $this->intOrZero($leftBlock, 'total_samples'),
            'categories' => $categories,
        ];
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, array<string, mixed>>
     */
    private function metricBlock(array $report): array
    {
        $metrics = $report['metrics'] ?? null;
        if (! is_array($metrics)) {
            return [];
        }

        $out = [];
        foreach ($metrics as $name => $aggregate) {
            if (is_string($name) && is_array($aggregate)) {
                $out[$name] = $aggregate;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, array<string, mixed>>
     */
    private function cohortIndex(array $report): array
    {
        $cohorts = $report['cohorts'] ?? null;
        if (! is_array($cohorts)) {
            return [];
        }

        $out = [];
        foreach ($cohorts as $cohort) {
            if (! is_array($cohort)) {
                continue;
            }

            $key = $this->cohortIndexKey($cohort);
            if ($key === null) {
                continue;
            }

            $out[$key] = $cohort;
        }

        return $out;
    }

    /**
     * Collision-free index key for the cohort lookup map. Tagged
     * cohorts are namespaced under "tag:<name>" and the synthetic
     * untagged bucket lives under a null-byte sentinel that cannot
     * appear in a real tag string. This guarantees that a dataset
     * carrying a literal `__untagged__` tag does NOT collide with the
     * untagged bucket the report renderer emits.
     *
     * @param  array<string, mixed>  $cohort
     */
    private function cohortIndexKey(array $cohort): ?string
    {
        if (($cohort['is_untagged'] ?? false) === true) {
            return "\0untagged\0";
        }

        $name = $cohort['name'] ?? null;
        if (! is_string($name) || $name === '') {
            return null;
        }

        return 'tag:'.$name;
    }

    /**
     * Display tag emitted to API clients. Untagged surfaces as
     * `__untagged__` for backwards-compatible UI labels; tagged
     * surfaces as the raw tag string.
     *
     * @param  array<string, mixed>  $cohort
     */
    private function cohortDisplayTag(array $cohort): string
    {
        if ($this->cohortIsUntagged($cohort)) {
            return '__untagged__';
        }

        $name = $cohort['name'] ?? null;

        return is_string($name) ? $name : '';
    }

    /**
     * Stable client-facing key for diff rows. The human-facing `tag`
     * value stays backwards-compatible, but clients should key rows by
     * this discriminator so a literal `__untagged__` tag cannot collide
     * with the synthetic untagged bucket.
     *
     * @param  array<string, mixed>  $cohort
     */
    private function cohortPublicKey(array $cohort): string
    {
        if ($this->cohortIsUntagged($cohort)) {
            return 'untagged';
        }

        return 'tag:'.$this->cohortDisplayTag($cohort);
    }

    /**
     * @param  array<string, mixed>  $cohort
     */
    private function cohortIsUntagged(array $cohort): bool
    {
        return ($cohort['is_untagged'] ?? false) === true;
    }

    /**
     * Coerce a `metrics` block into an array, tolerating non-array
     * payloads from a partially malformed report. Without this guard,
     * `cohortMetricsDelta(array, array)` would TypeError on a string
     * `metrics` value, producing a 500 instead of a partial diff.
     *
     * @param  array<string, mixed>  $cohortOrCategory
     * @return array<string, mixed>
     */
    private function metricsBlock(array $cohortOrCategory): array
    {
        $metrics = $cohortOrCategory['metrics'] ?? null;

        return is_array($metrics) ? $metrics : [];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, array<string, mixed>>
     */
    private function adversarialCategoryIndex(array $block): array
    {
        $categories = $block['categories'] ?? null;
        if (! is_array($categories)) {
            return [];
        }

        $out = [];
        foreach ($categories as $category) {
            if (! is_array($category)) {
                continue;
            }

            $name = $category['category'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $out[$name] = $category;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array<string, array{mean: float, pass_rate: float}>
     */
    private function cohortMetricsDelta(array $left, array $right): array
    {
        $metricNames = [];
        foreach ($left as $name => $_) {
            if (is_string($name)) {
                $metricNames[$name] = true;
            }
        }
        foreach ($right as $name => $_) {
            if (is_string($name)) {
                $metricNames[$name] = true;
            }
        }

        $names = array_keys($metricNames);
        sort($names);

        $out = [];
        foreach ($names as $name) {
            $leftAggregate = is_array($left[$name] ?? null) ? $left[$name] : [];
            $rightAggregate = is_array($right[$name] ?? null) ? $right[$name] : [];
            $out[$name] = $this->aggregateDelta($leftAggregate, $rightAggregate);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @return array{mean: float, pass_rate: float}
     */
    private function aggregateDelta(array $left, array $right): array
    {
        return [
            'mean' => $this->floatOrZero($right, 'mean') - $this->floatOrZero($left, 'mean'),
            'pass_rate' => $this->floatOrZero($right, 'pass_rate') - $this->floatOrZero($left, 'pass_rate'),
        ];
    }

    /**
     * @param  array<string, array{mean: float, pass_rate: float}>  $metricsDelta
     */
    private function cohortStatus(array $metricsDelta): string
    {
        $regressed = false;
        $improved = false;
        foreach ($metricsDelta as $delta) {
            $passDelta = $delta['pass_rate'];
            if ($passDelta < -self::REGRESSION_EPSILON) {
                $regressed = true;
            } elseif ($passDelta > self::REGRESSION_EPSILON) {
                $improved = true;
            }
        }

        if ($regressed) {
            return 'regressed';
        }

        if ($improved) {
            return 'improved';
        }

        return 'stable';
    }

    /**
     * @param  array<string, mixed>  $right
     * @param  array<string, mixed>  $left
     */
    private function floatDelta(array $right, array $left, string $key): float
    {
        return $this->floatOrZero($right, $key) - $this->floatOrZero($left, $key);
    }

    /**
     * @param  array<string, mixed>  $right
     * @param  array<string, mixed>  $left
     */
    private function intDelta(array $right, array $left, string $key): int
    {
        return $this->intOrZero($right, $key) - $this->intOrZero($left, $key);
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function intOrZero(array $report, string $key): int
    {
        $value = $report[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function floatOrZero(array $report, string $key): float
    {
        $value = $report[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return 0.0;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function floatOrNull(array $report, string $key): ?float
    {
        $value = $report[$key] ?? null;
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function stringOrNull(array $report, string $key): ?string
    {
        $value = $report[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
