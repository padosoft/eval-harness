<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\EvalSets;

use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Reports\EvalReport;

/**
 * Resumable progress summary for a multi-dataset eval-set run.
 */
final class EvalSetManifest
{
    public const SCHEMA_VERSION = 'eval-harness.eval-set-manifest.v1';

    public readonly string $evalSetName;

    /** @var list<EvalSetManifestEntry> */
    public readonly array $entries;

    /** @var array<string, EvalSetManifestEntry> */
    private array $entriesByDataset = [];

    /**
     * @param  array<array-key, mixed>  $entries
     */
    public function __construct(
        string $evalSetName,
        array $entries,
        public readonly float $startedAt,
        public readonly float $updatedAt,
        public readonly string $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($evalSetName === '' || $evalSetName !== trim($evalSetName)) {
            throw new EvalRunException('Eval set manifest name must be a non-empty string without leading or trailing whitespace.');
        }
        $this->evalSetName = $evalSetName;

        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new EvalRunException(sprintf(
                "Eval set manifest '%s' uses unsupported schema version '%s'. Supported version: %s.",
                $evalSetName,
                $schemaVersion,
                self::SCHEMA_VERSION,
            ));
        }

        if ($startedAt < 0.0 || $updatedAt < 0.0) {
            throw new EvalRunException(sprintf("Eval set manifest '%s' timestamps must be non-negative.", $evalSetName));
        }

        if ($updatedAt < $startedAt) {
            throw new EvalRunException(sprintf("Eval set manifest '%s' updated_at cannot be earlier than started_at.", $evalSetName));
        }

        if ($entries === []) {
            throw new EvalRunException(sprintf("Eval set manifest '%s' must include at least one dataset entry.", $evalSetName));
        }

        if (! array_is_list($entries)) {
            throw new EvalRunException(sprintf("Eval set manifest '%s' entries must be a zero-based list.", $evalSetName));
        }

        $normalizedEntries = [];
        $seen = [];
        foreach ($entries as $index => $entry) {
            if (! $entry instanceof EvalSetManifestEntry) {
                throw new EvalRunException(sprintf(
                    "Eval set manifest '%s' entry at index %d must be a %s instance; got %s.",
                    $evalSetName,
                    $index,
                    EvalSetManifestEntry::class,
                    get_debug_type($entry),
                ));
            }

            $key = EvalSetDefinition::datasetNameKey($entry->datasetName);
            if (isset($seen[$key])) {
                throw new EvalRunException(sprintf(
                    "Eval set manifest '%s' contains duplicate dataset '%s'.",
                    $evalSetName,
                    $entry->datasetName,
                ));
            }

            $seen[$key] = true;
            $normalizedEntries[] = $entry;
        }

        $this->entries = $normalizedEntries;
        foreach ($normalizedEntries as $entry) {
            $this->entriesByDataset[EvalSetDefinition::datasetNameKey($entry->datasetName)] = $entry;
        }
    }

    public static function start(EvalSetDefinition $definition, ?float $now = null): self
    {
        $now ??= microtime(true);

        return new self(
            evalSetName: $definition->name,
            entries: array_map(
                static fn (string $datasetName): EvalSetManifestEntry => EvalSetManifestEntry::pending($datasetName),
                $definition->datasetNames,
            ),
            startedAt: $now,
            updatedAt: $now,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromJson(array $payload): self
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        $evalSetName = $payload['eval_set'] ?? null;
        $startedAt = $payload['started_at'] ?? null;
        $updatedAt = $payload['updated_at'] ?? null;
        $datasets = $payload['datasets'] ?? null;

        if (! is_string($schemaVersion) || ! is_string($evalSetName)) {
            throw new EvalRunException('Eval set manifest requires string schema_version and eval_set fields.');
        }

        if (! is_int($startedAt) && ! is_float($startedAt)) {
            throw new EvalRunException("Eval set manifest field 'started_at' must be numeric.");
        }

        if (! is_int($updatedAt) && ! is_float($updatedAt)) {
            throw new EvalRunException("Eval set manifest field 'updated_at' must be numeric.");
        }

        if (! is_array($datasets) || ! array_is_list($datasets)) {
            throw new EvalRunException("Eval set manifest field 'datasets' must be a zero-based list.");
        }

        $entries = [];
        foreach ($datasets as $index => $entryPayload) {
            if (! is_array($entryPayload)) {
                throw new EvalRunException(sprintf(
                    'Eval set manifest dataset entry at index %d must be an object.',
                    $index,
                ));
            }

            $entries[] = EvalSetManifestEntry::fromJson(self::stringKeyedPayload($entryPayload, 'dataset entry'));
        }

        return new self(
            evalSetName: $evalSetName,
            entries: $entries,
            startedAt: (float) $startedAt,
            updatedAt: (float) $updatedAt,
            schemaVersion: $schemaVersion,
        );
    }

    /**
     * @return array{schema_version: string, eval_set: string, started_at: float, updated_at: float, datasets: list<array<string, mixed>>}
     */
    public function toJson(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'eval_set' => $this->evalSetName,
            'started_at' => $this->startedAt,
            'updated_at' => $this->updatedAt,
            'datasets' => array_map(
                static fn (EvalSetManifestEntry $entry): array => $entry->toJson(),
                $this->entries,
            ),
        ];
    }

    public function assertMatches(EvalSetDefinition $definition): void
    {
        if ($this->evalSetName !== $definition->name) {
            throw new EvalRunException(sprintf(
                "Eval set manifest '%s' does not match eval set '%s'.",
                $this->evalSetName,
                $definition->name,
            ));
        }

        $manifestDatasetNames = array_map(
            static fn (EvalSetManifestEntry $entry): string => $entry->datasetName,
            $this->entries,
        );

        if ($manifestDatasetNames !== $definition->datasetNames) {
            throw new EvalRunException(sprintf(
                "Eval set manifest '%s' dataset order does not match the eval set definition.",
                $definition->name,
            ));
        }
    }

    public function markRunning(string $datasetName, ?float $now = null): self
    {
        $now ??= microtime(true);

        return $this->replaceEntry($datasetName, static fn (EvalSetManifestEntry $entry): EvalSetManifestEntry => $entry->running($now), $now);
    }

    public function markCompleted(string $datasetName, EvalReport $report): self
    {
        return $this->replaceEntry($datasetName, static fn (EvalSetManifestEntry $entry): EvalSetManifestEntry => $entry->completed($report), $report->finishedAt);
    }

    public function markFailed(string $datasetName, string $error, ?float $now = null): self
    {
        $now ??= microtime(true);

        return $this->replaceEntry($datasetName, static fn (EvalSetManifestEntry $entry): EvalSetManifestEntry => $entry->failed($error, $now), $now);
    }

    public function entryFor(string $datasetName): ?EvalSetManifestEntry
    {
        return $this->entriesByDataset[EvalSetDefinition::datasetNameKey($datasetName)] ?? null;
    }

    public function statusFor(string $datasetName): ?string
    {
        return $this->entryFor($datasetName)?->status;
    }

    /**
     * @return list<string>
     */
    public function completedDatasetNames(): array
    {
        return $this->datasetNamesWithStatus(EvalSetManifestEntry::STATUS_COMPLETED);
    }

    /**
     * @return list<string>
     */
    public function failedDatasetNames(): array
    {
        return $this->datasetNamesWithStatus(EvalSetManifestEntry::STATUS_FAILED);
    }

    public function isComplete(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->status !== EvalSetManifestEntry::STATUS_COMPLETED) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  callable(EvalSetManifestEntry): EvalSetManifestEntry  $replacement
     */
    private function replaceEntry(string $datasetName, callable $replacement, float $updatedAt): self
    {
        $key = EvalSetDefinition::datasetNameKey($datasetName);
        $found = false;
        $entries = [];
        foreach ($this->entries as $entry) {
            if (EvalSetDefinition::datasetNameKey($entry->datasetName) !== $key) {
                $entries[] = $entry;

                continue;
            }

            $entries[] = $replacement($entry);
            $found = true;
        }

        if (! $found) {
            throw new EvalRunException(sprintf(
                "Eval set manifest '%s' does not contain dataset '%s'.",
                $this->evalSetName,
                $datasetName,
            ));
        }

        return new self(
            evalSetName: $this->evalSetName,
            entries: $entries,
            startedAt: $this->startedAt,
            updatedAt: $updatedAt,
            schemaVersion: $this->schemaVersion,
        );
    }

    /**
     * @return list<string>
     */
    private function datasetNamesWithStatus(string $status): array
    {
        $datasetNames = [];
        foreach ($this->entries as $entry) {
            if ($entry->status === $status) {
                $datasetNames[] = $entry->datasetName;
            }
        }

        return $datasetNames;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function stringKeyedPayload(array $payload, string $context): array
    {
        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                throw new EvalRunException(sprintf('Eval set manifest %s must use string keys.', $context));
            }
        }

        return $payload;
    }
}
