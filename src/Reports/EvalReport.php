<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Reports;

use Padosoft\EvalHarness\Datasets\DatasetSchema;

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
    ) {}

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

        $values = $this->scoresFor($metricName);
        if ($values === []) {
            return 0.0;
        }

        sort($values);
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
     * @return list<float>
     */
    private function scoresFor(string $metricName): array
    {
        $values = [];
        foreach ($this->sampleResults as $result) {
            $score = $result->metricScores[$metricName] ?? null;
            if ($score === null) {
                continue;
            }
            $values[] = $score->score;
        }

        return $values;
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
