<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Adversarial;

use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Reports\EvalReport;

/**
 * Immutable summary for one adversarial run retained in a manifest.
 */
final class AdversarialRunManifestEntry
{
    /** @var array<string, array{mean: float, p50: float, p95: float, pass_rate: float}> */
    public readonly array $metrics;

    /** @var array<string, mixed> */
    public readonly array $adversarial;

    /**
     * @param  array<array-key, mixed>  $metrics
     * @param  array<string, mixed>  $adversarial
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $datasetName,
        public readonly string $reportSchemaVersion,
        public readonly float $startedAt,
        public readonly float $finishedAt,
        public readonly float $durationSeconds,
        public readonly int $totalSamples,
        public readonly int $totalFailures,
        public readonly float $macroF1,
        array $metrics,
        array $adversarial,
    ) {
        if ($runId === '' || $runId !== trim($runId)) {
            throw new EvalRunException('Adversarial run manifest entry run_id must be a non-empty string without leading or trailing whitespace.');
        }

        if ($datasetName === '' || $datasetName !== trim($datasetName)) {
            throw new EvalRunException('Adversarial run manifest entry dataset must be a non-empty string without leading or trailing whitespace.');
        }

        if ($reportSchemaVersion === '' || $reportSchemaVersion !== trim($reportSchemaVersion)) {
            throw new EvalRunException('Adversarial run manifest entry report_schema_version must be a non-empty string without leading or trailing whitespace.');
        }

        foreach ([
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
            'duration_seconds' => $durationSeconds,
            'macro_f1' => $macroF1,
        ] as $field => $value) {
            if ($value < 0.0 || is_nan($value) || is_infinite($value)) {
                throw new EvalRunException(sprintf("Adversarial run manifest entry field '%s' must be a finite non-negative number.", $field));
            }
        }

        if ($finishedAt < $startedAt) {
            throw new EvalRunException('Adversarial run manifest entry finished_at cannot be earlier than started_at.');
        }

        if (abs($durationSeconds - ($finishedAt - $startedAt)) > 0.000001) {
            throw new EvalRunException('Adversarial run manifest entry duration_seconds must match finished_at minus started_at.');
        }

        if ($totalSamples < 0 || $totalFailures < 0) {
            throw new EvalRunException('Adversarial run manifest entry sample/failure counts must be non-negative.');
        }

        $this->metrics = self::normalizeMetricMap($metrics, 'entry');
        $this->adversarial = self::normalizeAdversarialSummary($adversarial);

        $this->assertMetricAggregates($this->metrics);
        $this->assertAdversarialSummary($this->adversarial);
    }

    public static function fromReport(EvalReport $report, ?string $runId = null): self
    {
        $metrics = [];
        foreach ($report->metricNames() as $metricName) {
            $metrics[$metricName] = $report->metricAggregate($metricName);
        }

        return new self(
            runId: $runId ?? self::defaultRunId($report),
            datasetName: $report->datasetName,
            reportSchemaVersion: $report->schemaVersion,
            startedAt: $report->startedAt,
            finishedAt: $report->finishedAt,
            durationSeconds: $report->durationSeconds(),
            totalSamples: $report->totalSamples(),
            totalFailures: $report->totalFailures(),
            macroF1: $report->macroF1(),
            metrics: $metrics,
            adversarial: $report->adversarialSummary(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromJson(array $payload): self
    {
        return new self(
            runId: self::stringField($payload, 'run_id'),
            datasetName: self::stringField($payload, 'dataset'),
            reportSchemaVersion: self::stringField($payload, 'report_schema_version'),
            startedAt: self::floatField($payload, 'started_at'),
            finishedAt: self::floatField($payload, 'finished_at'),
            durationSeconds: self::floatField($payload, 'duration_seconds'),
            totalSamples: self::intField($payload, 'total_samples'),
            totalFailures: self::intField($payload, 'total_failures'),
            macroF1: self::floatField($payload, 'macro_f1'),
            metrics: self::metricsField($payload),
            adversarial: self::adversarialField($payload),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        return [
            'run_id' => $this->runId,
            'dataset' => $this->datasetName,
            'report_schema_version' => $this->reportSchemaVersion,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'duration_seconds' => $this->durationSeconds,
            'total_samples' => $this->totalSamples,
            'total_failures' => $this->totalFailures,
            'macro_f1' => $this->macroF1,
            'metrics' => $this->metrics,
            'adversarial' => $this->adversarial,
        ];
    }

    private static function defaultRunId(EvalReport $report): string
    {
        return hash('sha256', implode('|', [
            $report->datasetName,
            self::formatFloatForRunId($report->startedAt),
            self::formatFloatForRunId($report->finishedAt),
            (string) $report->totalSamples(),
            (string) $report->totalFailures(),
        ]));
    }

    private static function formatFloatForRunId(float $value): string
    {
        return number_format($value, 6, '.', '');
    }

    /**
     * @param  array<array-key, mixed>  $metrics
     */
    private function assertMetricAggregates(array $metrics): void
    {
        foreach ($metrics as $metricName => $aggregate) {
            if (! is_string($metricName) || $metricName === '' || $metricName !== trim($metricName)) {
                throw new EvalRunException('Adversarial run manifest metric names must be non-empty strings without leading or trailing whitespace.');
            }

            if (! is_array($aggregate)) {
                throw new EvalRunException(sprintf("Adversarial run manifest metric '%s' aggregate must be an object.", $metricName));
            }

            foreach (['mean', 'p50', 'p95', 'pass_rate'] as $field) {
                if (! array_key_exists($field, $aggregate)) {
                    throw new EvalRunException(sprintf("Adversarial run manifest metric '%s' is missing aggregate field '%s'.", $metricName, $field));
                }

                $value = $aggregate[$field];
                if (! is_float($value) && ! is_int($value)) {
                    throw new EvalRunException(sprintf("Adversarial run manifest metric '%s' aggregate '%s' must be numeric.", $metricName, $field));
                }

                $floatValue = (float) $value;
                if ($floatValue < 0.0 || $floatValue > 1.0 || is_nan($floatValue) || is_infinite($floatValue)) {
                    throw new EvalRunException(sprintf("Adversarial run manifest metric '%s' aggregate '%s' must be in [0, 1].", $metricName, $field));
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $adversarial
     */
    private function assertAdversarialSummary(array $adversarial): void
    {
        $totalSamples = $adversarial['total_samples'] ?? null;
        if (! is_int($totalSamples) || $totalSamples < 0) {
            throw new EvalRunException('Adversarial run manifest adversarial.total_samples must be a non-negative integer.');
        }

        foreach (['categories', 'compliance_frameworks'] as $field) {
            $value = $adversarial[$field] ?? null;
            if (! is_array($value) || ! array_is_list($value)) {
                throw new EvalRunException(sprintf('Adversarial run manifest adversarial.%s must be a zero-based list.', $field));
            }
        }

        foreach ($adversarial['categories'] as $index => $category) {
            if (! is_array($category)) {
                throw new EvalRunException(sprintf('Adversarial run manifest adversarial.categories[%d] must be an object.', $index));
            }

            $metrics = $category['metrics'] ?? null;
            if (! is_array($metrics)) {
                throw new EvalRunException(sprintf('Adversarial run manifest adversarial.categories[%d].metrics must be an object.', $index));
            }

            $this->assertMetricAggregates($metrics);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function stringField(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (! is_string($value)) {
            throw new EvalRunException(sprintf("Adversarial run manifest entry field '%s' must be a string.", $field));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function floatField(array $payload, string $field): float
    {
        $value = $payload[$field] ?? null;
        if (! is_int($value) && ! is_float($value)) {
            throw new EvalRunException(sprintf("Adversarial run manifest entry field '%s' must be numeric.", $field));
        }

        return (float) $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function intField(array $payload, string $field): int
    {
        $value = $payload[$field] ?? null;
        if (! is_int($value)) {
            throw new EvalRunException(sprintf("Adversarial run manifest entry field '%s' must be an integer.", $field));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array{mean: float, p50: float, p95: float, pass_rate: float}>
     */
    private static function metricsField(array $payload): array
    {
        $metrics = $payload['metrics'] ?? null;
        if (! is_array($metrics)) {
            throw new EvalRunException("Adversarial run manifest entry field 'metrics' must be an object.");
        }

        return self::normalizeMetricMap($metrics, 'entry');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function adversarialField(array $payload): array
    {
        $adversarial = $payload['adversarial'] ?? null;
        if (! is_array($adversarial)) {
            throw new EvalRunException("Adversarial run manifest entry field 'adversarial' must be an object.");
        }

        return self::normalizeAdversarialSummary($adversarial);
    }

    /**
     * @param  array<string, mixed>  $adversarial
     * @return array<string, mixed>
     */
    private static function normalizeAdversarialSummary(array $adversarial): array
    {
        $categories = $adversarial['categories'] ?? null;
        if (is_array($categories) && array_is_list($categories)) {
            foreach ($categories as $index => $category) {
                if (! is_array($category)) {
                    throw new EvalRunException(sprintf('Adversarial run manifest adversarial.categories[%d] must be an object.', $index));
                }

                $category['metrics'] = self::normalizeMetricMap(
                    self::arrayField($category, 'metrics', sprintf('adversarial.categories[%d]', $index)),
                    sprintf('adversarial category at index %d', $index),
                );
                $categories[$index] = $category;
            }

            $adversarial['categories'] = $categories;
        }

        return $adversarial;
    }

    /**
     * @param  array<array-key, mixed>  $metrics
     * @return array<string, array{mean: float, p50: float, p95: float, pass_rate: float}>
     */
    private static function normalizeMetricMap(array $metrics, string $context): array
    {
        $normalized = [];
        foreach ($metrics as $metricName => $aggregate) {
            if (! is_string($metricName) || ! is_array($aggregate)) {
                throw new EvalRunException(sprintf('Adversarial run manifest %s metrics must map metric names to aggregate objects.', $context));
            }

            $normalized[$metricName] = [
                'mean' => self::metricAggregateField($metricName, $aggregate, 'mean'),
                'p50' => self::metricAggregateField($metricName, $aggregate, 'p50'),
                'p95' => self::metricAggregateField($metricName, $aggregate, 'p95'),
                'pass_rate' => self::metricAggregateField($metricName, $aggregate, 'pass_rate'),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<array-key, mixed>  $aggregate
     */
    private static function metricAggregateField(string $metricName, array $aggregate, string $field): float
    {
        $value = $aggregate[$field] ?? null;
        if (! is_int($value) && ! is_float($value)) {
            throw new EvalRunException(sprintf("Adversarial run manifest metric '%s' aggregate '%s' must be numeric.", $metricName, $field));
        }

        return (float) $value;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private static function arrayField(array $payload, string $field, string $context): array
    {
        $value = $payload[$field] ?? null;
        if (! is_array($value)) {
            throw new EvalRunException(sprintf('Adversarial run manifest %s.%s must be an object.', $context, $field));
        }

        return $value;
    }
}
