<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Reports;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\DatasetSchema;
use Padosoft\EvalHarness\Exceptions\ReportSchemaException;

/**
 * Read-only outcome of an eval run.
 *
 * Aggregates:
 *   - per-metric mean over scored samples
 *   - per-metric p50 / p95 percentiles
 *   - macro-F1 (mean of binarised scores at threshold 0.5) — useful
 *     for boolean-style metrics like exact-match; less meaningful
 *     for graded scores but cheap to compute.
 *
 * Two renderers turn the report into a payload:
 *   - {@see MarkdownReportRenderer} — human-readable.
 *   - {@see JsonReportRenderer} — machine-readable (CI gate).
 *
 * The convenience methods on this class delegate to the renderers
 * so callers can write `$report->toMarkdown()` without resolving
 * a service. For tests + DI use the renderers directly.
 */
final class EvalReport
{
    private const UNTAGGED_COHORT_KEY = "\0eval-harness.untagged";

    /**
     * @param  list<SampleResult>  $sampleResults
     * @param  list<SampleFailure>  $failures
     */
    public function __construct(
        public readonly string $datasetName,
        public readonly array $sampleResults,
        public readonly array $failures,
        public readonly float $startedAt,
        public readonly float $finishedAt,
        public readonly string $schemaVersion = ReportSchema::VERSION,
        public readonly string $datasetSchemaVersion = DatasetSchema::VERSION,
    ) {
        if ($schemaVersion !== ReportSchema::VERSION) {
            throw new ReportSchemaException(
                sprintf(
                    "Report for dataset '%s' uses unsupported schema version '%s'. Supported version: %s.",
                    $datasetName,
                    $schemaVersion,
                    ReportSchema::VERSION,
                ),
            );
        }

        if (! DatasetSchema::isSupported($datasetSchemaVersion)) {
            throw new ReportSchemaException(
                sprintf(
                    "Report for dataset '%s' references unsupported dataset schema version '%s'. Supported versions: %s.",
                    $datasetName,
                    $datasetSchemaVersion,
                    implode(', ', DatasetSchema::SUPPORTED_VERSIONS),
                ),
            );
        }
    }

    public function durationSeconds(): float
    {
        return max(0.0, $this->finishedAt - $this->startedAt);
    }

    public function totalSamples(): int
    {
        return count($this->sampleResults);
    }

    public function totalFailures(): int
    {
        return count($this->failures);
    }

    /**
     * Collect every metric name observed in this run, INCLUDING
     * metrics that failed on every sample. Without the failures
     * walk, a metric whose entire surface raised would disappear
     * from the report and a total outage would render as "metric
     * was never configured", which is misleading.
     *
     * @return list<string>
     */
    public function metricNames(): array
    {
        $names = [];
        foreach ($this->sampleResults as $result) {
            foreach ($result->metricScores as $name => $_score) {
                $names[$name] = true;
            }
        }
        foreach ($this->failures as $failure) {
            $names[$failure->metricName] = true;
        }

        return array_keys($names);
    }

    public function meanScore(string $metricName): float
    {
        $values = $this->scoresFor($metricName);
        if ($values === []) {
            return 0.0;
        }

        return array_sum($values) / count($values);
    }

    public function percentile(string $metricName, float $percentile): float
    {
        if ($percentile < 0.0 || $percentile > 100.0) {
            return 0.0;
        }

        return $this->percentileForValues($this->scoresFor($metricName), $percentile);
    }

    /**
     * Mean of binarised (>= 0.5 → 1, else 0) scores over the metric.
     * For exact-match this IS the pass rate; for cosine / judge it's
     * a coarse proxy.
     *
     * Note: despite the name this is a macro-averaged pass rate, not a
     * true F1 (which would require separate precision and recall terms).
     * It is labelled "macroF1" for historical reasons and because
     * for binary metrics (exact-match) pass rate == F1 when threshold=0.5.
     */
    public function macroF1(string $metricName = ''): float
    {
        $names = $metricName === '' ? $this->metricNames() : [$metricName];
        if ($names === []) {
            return 0.0;
        }

        $totals = 0.0;
        $count = 0;
        foreach ($names as $name) {
            $values = $this->scoresFor($name);
            if ($values === []) {
                continue;
            }
            $passed = 0;
            foreach ($values as $v) {
                if ($v >= 0.5) {
                    $passed++;
                }
            }
            $totals += $passed / count($values);
            $count++;
        }

        if ($count === 0) {
            return 0.0;
        }

        return $totals / $count;
    }

    /**
     * @return array{mean: float, p50: float, p95: float, pass_rate: float}
     */
    public function metricAggregate(string $metricName): array
    {
        return $this->aggregateValues($this->scoresFor($metricName));
    }

    /**
     * @return array<string, list<array{min: float, max: float, count: int}>>
     */
    public function metricDistributions(int $buckets = 10): array
    {
        $distributions = [];
        foreach ($this->metricNames() as $metricName) {
            $distributions[$metricName] = $this->histogramForMetric($metricName, $buckets);
        }

        return $distributions;
    }

    /**
     * @return list<array{min: float, max: float, count: int}>
     */
    public function histogramForMetric(string $metricName, int $buckets = 10): array
    {
        $bucketCount = max(1, $buckets);
        $width = 1.0 / $bucketCount;

        $histogram = [];
        for ($index = 0; $index < $bucketCount; $index++) {
            $histogram[] = [
                'min' => $this->scoreBoundary($index * $width),
                'max' => $index === $bucketCount - 1 ? 1.0 : $this->scoreBoundary(($index + 1) * $width),
                'count' => 0,
            ];
        }

        foreach ($this->scoresFor($metricName) as $score) {
            $normalizedScore = $this->scoreBoundary($score);
            $index = $this->bucketIndexForScore($normalizedScore, $histogram);
            $histogram[$index]['count']++;
        }

        return $histogram;
    }

    private function scoreBoundary(float $value): float
    {
        return (float) round($value, 10);
    }

    /**
     * @param  list<array{min: float, max: float, count: int}>  $histogram
     */
    private function bucketIndexForScore(float $score, array $histogram): int
    {
        $lastIndex = count($histogram) - 1;

        foreach ($histogram as $index => $bucket) {
            if ($index === $lastIndex) {
                return $index;
            }

            if ($score >= $bucket['min'] && $score < $bucket['max']) {
                return $index;
            }
        }

        return $lastIndex;
    }

    /**
     * @return list<array{name: string|null, label: string, is_untagged: bool, sample_count: int, metrics: array<string, array{mean: float, p50: float, p95: float, pass_rate: float}>}>
     */
    public function cohortSummaries(): array
    {
        /** @var array<string, array{name: string|null, label: string, is_untagged: bool, results: list<SampleResult>}> $cohorts */
        $cohorts = [];

        foreach ($this->sampleResults as $result) {
            $tags = $this->tagsForSample($result->sample);

            if ($tags === []) {
                $cohorts[self::UNTAGGED_COHORT_KEY] ??= [
                    'name' => null,
                    'label' => '(untagged)',
                    'is_untagged' => true,
                    'results' => [],
                ];
                $cohorts[self::UNTAGGED_COHORT_KEY]['results'][] = $result;

                continue;
            }

            foreach ($tags as $tag) {
                $key = 'tag:'.$tag;
                $cohorts[$key] ??= [
                    'name' => $tag,
                    'label' => $tag,
                    'is_untagged' => false,
                    'results' => [],
                ];
                $cohorts[$key]['results'][] = $result;
            }
        }

        $cohortKeys = $this->orderedCohortKeys($cohorts);
        $metricNames = $this->metricNames();
        $summaries = [];

        foreach ($cohortKeys as $cohortKey) {
            $cohort = $cohorts[$cohortKey];
            $results = $cohort['results'];
            $metrics = [];

            foreach ($metricNames as $metricName) {
                $metrics[$metricName] = $this->aggregateValues(
                    $this->scoresForResults($results, $metricName),
                );
            }

            $summaries[] = [
                'name' => $cohort['name'],
                'label' => $cohort['label'],
                'is_untagged' => $cohort['is_untagged'],
                'sample_count' => count($results),
                'metrics' => $metrics,
            ];
        }

        return $summaries;
    }

    /**
     * @return list<string>
     */
    public function tagsForSample(DatasetSample $sample): array
    {
        $rawTags = $sample->metadata['tags'] ?? null;
        $tags = [];

        if (is_string($rawTags)) {
            $tag = trim($rawTags);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        if (is_array($rawTags)) {
            foreach ($rawTags as $rawTag) {
                if (! is_string($rawTag)) {
                    continue;
                }

                $tag = trim($rawTag);
                if ($tag !== '') {
                    $tags[] = $tag;
                }
            }
        }

        $tags = array_values(array_unique($tags));

        return $tags;
    }

    /**
     * @return list<float>
     */
    private function scoresFor(string $metricName): array
    {
        return $this->scoresForResults($this->sampleResults, $metricName);
    }

    /**
     * @param  list<SampleResult>  $results
     * @return list<float>
     */
    private function scoresForResults(array $results, string $metricName): array
    {
        $values = [];
        foreach ($results as $result) {
            $score = $result->metricScores[$metricName] ?? null;
            if ($score === null) {
                continue;
            }
            $values[] = $score->score;
        }

        return $values;
    }

    /**
     * @param  list<float>  $values
     * @return array{mean: float, p50: float, p95: float, pass_rate: float}
     */
    private function aggregateValues(array $values): array
    {
        if ($values === []) {
            return [
                'mean' => 0.0,
                'p50' => 0.0,
                'p95' => 0.0,
                'pass_rate' => 0.0,
            ];
        }

        $passed = 0;
        foreach ($values as $value) {
            if ($value >= 0.5) {
                $passed++;
            }
        }

        $sortedValues = $values;
        sort($sortedValues);

        return [
            'mean' => array_sum($values) / count($values),
            'p50' => $this->percentileForSortedValues($sortedValues, 50.0),
            'p95' => $this->percentileForSortedValues($sortedValues, 95.0),
            'pass_rate' => (float) ($passed / count($values)),
        ];
    }

    /**
     * @param  list<float>  $values
     */
    private function percentileForValues(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);

        return $this->percentileForSortedValues($values, $percentile);
    }

    /**
     * @param  list<float>  $values
     */
    private function percentileForSortedValues(array $values, float $percentile): float
    {
        $rank = ($percentile / 100.0) * (count($values) - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);
        if ($lower === $upper) {
            return $values[$lower];
        }
        $weight = $rank - $lower;

        return $values[$lower] * (1.0 - $weight) + $values[$upper] * $weight;
    }

    /**
     * @param  array<string, array{name: string|null, label: string, is_untagged: bool, results: list<SampleResult>}>  $cohorts
     * @return list<string>
     */
    private function orderedCohortKeys(array $cohorts): array
    {
        $tagKeys = array_values(array_filter(
            array_keys($cohorts),
            static fn (string $key): bool => $key !== self::UNTAGGED_COHORT_KEY,
        ));
        usort(
            $tagKeys,
            static fn (string $left, string $right): int => strcmp($cohorts[$left]['label'], $cohorts[$right]['label']),
        );

        if (isset($cohorts[self::UNTAGGED_COHORT_KEY])) {
            $tagKeys[] = self::UNTAGGED_COHORT_KEY;
        }

        return $tagKeys;
    }

    public function toMarkdown(): string
    {
        return (new MarkdownReportRenderer)->render($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        return (new JsonReportRenderer)->render($this);
    }
}
