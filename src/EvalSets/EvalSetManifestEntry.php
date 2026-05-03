<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\EvalSets;

use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Reports\EvalReport;

/**
 * Per-dataset resume state inside an eval-set manifest.
 */
final class EvalSetManifestEntry
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_RUNNING,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    public readonly string $datasetName;

    public function __construct(
        string $datasetName,
        public readonly string $status,
        public readonly ?float $startedAt = null,
        public readonly ?float $finishedAt = null,
        public readonly ?float $durationSeconds = null,
        public readonly ?string $reportSchemaVersion = null,
        public readonly ?int $totalSamples = null,
        public readonly ?int $totalFailures = null,
        public readonly ?string $error = null,
    ) {
        if ($datasetName === '' || $datasetName !== trim($datasetName)) {
            throw new EvalRunException('Eval set manifest dataset name must be a non-empty string without leading or trailing whitespace.');
        }
        $this->datasetName = $datasetName;

        if (! in_array($status, self::STATUSES, true)) {
            throw new EvalRunException(sprintf(
                "Eval set manifest dataset '%s' has unsupported status '%s'.",
                $datasetName,
                $status,
            ));
        }

        $this->assertTimingFieldsAreValid();
        $this->assertStatusFieldsAreConsistent();
    }

    public static function pending(string $datasetName): self
    {
        return new self(datasetName: $datasetName, status: self::STATUS_PENDING);
    }

    public function running(float $now): self
    {
        $this->assertCanTransitionTo(self::STATUS_RUNNING);

        return new self(
            datasetName: $this->datasetName,
            status: self::STATUS_RUNNING,
            startedAt: $this->startedAt ?? $now,
        );
    }

    public function completed(EvalReport $report): self
    {
        $this->assertCanTransitionTo(self::STATUS_COMPLETED);

        if ($report->datasetName !== $this->datasetName) {
            throw new EvalRunException(sprintf(
                "Eval set manifest dataset '%s' cannot be completed with report for dataset '%s'.",
                $this->datasetName,
                $report->datasetName,
            ));
        }

        $startedAt = $this->startedAt ?? $report->startedAt;
        $finishedAt = $report->finishedAt;

        return new self(
            datasetName: $this->datasetName,
            status: self::STATUS_COMPLETED,
            startedAt: $startedAt,
            finishedAt: $finishedAt,
            durationSeconds: max(0.0, $finishedAt - $startedAt),
            reportSchemaVersion: $report->schemaVersion,
            totalSamples: $report->totalSamples(),
            totalFailures: $report->totalFailures(),
        );
    }

    public function failed(string $error, float $now): self
    {
        $this->assertCanTransitionTo(self::STATUS_FAILED);

        $error = trim($error);
        if ($error === '') {
            throw new EvalRunException(sprintf(
                "Eval set manifest dataset '%s' failure error must be a non-empty string.",
                $this->datasetName,
            ));
        }

        return new self(
            datasetName: $this->datasetName,
            status: self::STATUS_FAILED,
            startedAt: $this->startedAt ?? $now,
            finishedAt: $now,
            durationSeconds: $this->startedAt !== null ? max(0.0, $now - $this->startedAt) : 0.0,
            error: $error,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toJson(): array
    {
        return [
            'dataset' => $this->datasetName,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'duration_seconds' => $this->durationSeconds,
            'report_schema_version' => $this->reportSchemaVersion,
            'total_samples' => $this->totalSamples,
            'total_failures' => $this->totalFailures,
            'error' => $this->error,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromJson(array $payload): self
    {
        $datasetName = $payload['dataset'] ?? null;
        $status = $payload['status'] ?? null;

        if (! is_string($datasetName) || ! is_string($status)) {
            throw new EvalRunException('Eval set manifest entry requires string dataset and status fields.');
        }

        return new self(
            datasetName: $datasetName,
            status: $status,
            startedAt: self::nullableFloat($payload['started_at'] ?? null, 'started_at'),
            finishedAt: self::nullableFloat($payload['finished_at'] ?? null, 'finished_at'),
            durationSeconds: self::nullableFloat($payload['duration_seconds'] ?? null, 'duration_seconds'),
            reportSchemaVersion: self::nullableString($payload['report_schema_version'] ?? null, 'report_schema_version'),
            totalSamples: self::nullableInt($payload['total_samples'] ?? null, 'total_samples'),
            totalFailures: self::nullableInt($payload['total_failures'] ?? null, 'total_failures'),
            error: self::nullableString($payload['error'] ?? null, 'error'),
        );
    }

    private static function nullableFloat(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        throw new EvalRunException(sprintf("Eval set manifest field '%s' must be numeric or null.", $field));
    }

    private static function nullableInt(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        throw new EvalRunException(sprintf("Eval set manifest field '%s' must be an integer or null.", $field));
    }

    private static function nullableString(mixed $value, string $field): ?string
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        throw new EvalRunException(sprintf("Eval set manifest field '%s' must be a string or null.", $field));
    }

    private function assertTimingFieldsAreValid(): void
    {
        foreach ([
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'duration_seconds' => $this->durationSeconds,
        ] as $field => $value) {
            if ($value !== null && $value < 0.0) {
                throw new EvalRunException(sprintf(
                    "Eval set manifest field '%s' must be non-negative or null.",
                    $field,
                ));
            }
        }

        foreach ([
            'total_samples' => $this->totalSamples,
            'total_failures' => $this->totalFailures,
        ] as $field => $value) {
            if ($value !== null && $value < 0) {
                throw new EvalRunException(sprintf(
                    "Eval set manifest field '%s' must be non-negative or null.",
                    $field,
                ));
            }
        }
    }

    private function assertStatusFieldsAreConsistent(): void
    {
        if ($this->status === self::STATUS_PENDING) {
            if (
                $this->startedAt !== null
                || $this->finishedAt !== null
                || $this->durationSeconds !== null
                || $this->reportSchemaVersion !== null
                || $this->totalSamples !== null
                || $this->totalFailures !== null
                || $this->error !== null
            ) {
                throw new EvalRunException(sprintf(
                    "Eval set manifest dataset '%s' pending status cannot include progress fields.",
                    $this->datasetName,
                ));
            }
        }

        if ($this->status === self::STATUS_RUNNING && $this->startedAt === null) {
            throw new EvalRunException(sprintf(
                "Eval set manifest dataset '%s' running status requires started_at.",
                $this->datasetName,
            ));
        }

        if ($this->status === self::STATUS_RUNNING) {
            if (
                $this->finishedAt !== null
                || $this->durationSeconds !== null
                || $this->reportSchemaVersion !== null
                || $this->totalSamples !== null
                || $this->totalFailures !== null
                || $this->error !== null
            ) {
                throw new EvalRunException(sprintf(
                    "Eval set manifest dataset '%s' running status cannot include terminal fields.",
                    $this->datasetName,
                ));
            }
        }

        if ($this->status === self::STATUS_COMPLETED) {
            if (
                $this->startedAt === null
                || $this->finishedAt === null
                || $this->durationSeconds === null
                || $this->reportSchemaVersion === null
                || $this->totalSamples === null
                || $this->totalFailures === null
            ) {
                throw new EvalRunException(sprintf(
                    "Eval set manifest dataset '%s' completed status requires report summary fields.",
                    $this->datasetName,
                ));
            }

            if ($this->error !== null) {
                throw new EvalRunException(sprintf(
                    "Eval set manifest dataset '%s' completed status cannot include an error.",
                    $this->datasetName,
                ));
            }
        }

        if ($this->status === self::STATUS_FAILED) {
            if ($this->startedAt === null || $this->finishedAt === null || $this->durationSeconds === null) {
                throw new EvalRunException(sprintf(
                    "Eval set manifest dataset '%s' failed status requires timing fields.",
                    $this->datasetName,
                ));
            }

            if ($this->error === null || trim($this->error) === '') {
                throw new EvalRunException(sprintf(
                    "Eval set manifest dataset '%s' failed status requires an error.",
                    $this->datasetName,
                ));
            }
        }

        if (
            $this->status === self::STATUS_FAILED
            && ($this->reportSchemaVersion !== null || $this->totalSamples !== null || $this->totalFailures !== null)
        ) {
            throw new EvalRunException(sprintf(
                "Eval set manifest dataset '%s' failed status cannot include report summary fields.",
                $this->datasetName,
            ));
        }
    }

    private function assertCanTransitionTo(string $nextStatus): void
    {
        if (! in_array($nextStatus, self::STATUSES, true)) {
            throw new EvalRunException(sprintf(
                "Eval set manifest dataset '%s' cannot transition to unsupported status '%s'.",
                $this->datasetName,
                $nextStatus,
            ));
        }

        if (in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_FAILED], true)) {
            throw new EvalRunException(sprintf(
                "Eval set manifest dataset '%s' cannot transition from terminal status '%s' to '%s'.",
                $this->datasetName,
                $this->status,
                $nextStatus,
            ));
        }
    }
}
