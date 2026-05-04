<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Adversarial;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Retains the latest adversarial run summaries for future regression gates.
 */
final class AdversarialRunManifest
{
    public const SCHEMA_VERSION = 'eval-harness.adversarial-runs.v1';

    public readonly string $name;

    /** @var list<AdversarialRunManifestEntry> */
    public readonly array $runs;

    /**
     * @param  array<array-key, mixed>  $runs
     */
    public function __construct(
        string $name,
        array $runs,
        public readonly float $updatedAt,
        public readonly string $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($name === '' || $name !== trim($name)) {
            throw new EvalRunException('Adversarial run manifest name must be a non-empty string without leading or trailing whitespace.');
        }
        $this->name = $name;

        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new EvalRunException(sprintf(
                "Adversarial run manifest '%s' uses unsupported schema version '%s'. Supported version: %s.",
                $name,
                $schemaVersion,
                self::SCHEMA_VERSION,
            ));
        }

        if ($updatedAt < 0.0 || is_nan($updatedAt) || is_infinite($updatedAt)) {
            throw new EvalRunException(sprintf("Adversarial run manifest '%s' updated_at must be a finite non-negative number.", $name));
        }

        if (! array_is_list($runs)) {
            throw new EvalRunException(sprintf("Adversarial run manifest '%s' runs must be a zero-based list.", $name));
        }

        $normalizedRuns = [];
        $seen = [];
        foreach ($runs as $index => $run) {
            if (! $run instanceof AdversarialRunManifestEntry) {
                throw new EvalRunException(sprintf(
                    "Adversarial run manifest '%s' run at index %d must be a %s instance; got %s.",
                    $name,
                    $index,
                    AdversarialRunManifestEntry::class,
                    get_debug_type($run),
                ));
            }

            if (isset($seen[$run->runId])) {
                throw new EvalRunException(sprintf("Adversarial run manifest '%s' contains duplicate run_id '%s'.", $name, $run->runId));
            }

            $seen[$run->runId] = true;
            $normalizedRuns[] = $run;
        }

        $this->runs = $normalizedRuns;
    }

    public static function empty(string $name, ?float $now = null): self
    {
        return new self(
            name: $name,
            runs: [],
            updatedAt: $now ?? microtime(true),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromJson(array $payload): self
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        $name = $payload['manifest'] ?? null;
        $updatedAt = $payload['updated_at'] ?? null;
        $runs = $payload['runs'] ?? null;

        if (! is_string($schemaVersion) || ! is_string($name)) {
            throw new EvalRunException('Adversarial run manifest requires string schema_version and manifest fields.');
        }

        if (! is_int($updatedAt) && ! is_float($updatedAt)) {
            throw new EvalRunException("Adversarial run manifest field 'updated_at' must be numeric.");
        }

        if (! is_array($runs) || ! array_is_list($runs)) {
            throw new EvalRunException("Adversarial run manifest field 'runs' must be a zero-based list.");
        }

        $entries = [];
        foreach ($runs as $index => $runPayload) {
            if (! is_array($runPayload)) {
                throw new EvalRunException(sprintf('Adversarial run manifest run at index %d must be an object.', $index));
            }

            $entries[] = AdversarialRunManifestEntry::fromJson(self::stringKeyedPayload($runPayload, 'run entry'));
        }

        return new self(
            name: $name,
            runs: $entries,
            updatedAt: (float) $updatedAt,
            schemaVersion: $schemaVersion,
        );
    }

    public function record(AdversarialRunManifestEntry $entry, int $maxRuns = 10, ?float $now = null): self
    {
        if ($maxRuns < 1) {
            throw new EvalRunException('Adversarial run manifest retention must keep at least one run.');
        }

        $runs = [$entry];
        foreach ($this->runs as $run) {
            if ($run->runId !== $entry->runId) {
                $runs[] = $run;
            }
        }

        usort($runs, static function (AdversarialRunManifestEntry $left, AdversarialRunManifestEntry $right): int {
            $byFinishedAt = $right->finishedAt <=> $left->finishedAt;
            if ($byFinishedAt !== 0) {
                return $byFinishedAt;
            }

            return strcmp($left->runId, $right->runId);
        });

        return new self(
            name: $this->name,
            runs: $this->retainRuns($runs, $maxRuns),
            updatedAt: $now ?? microtime(true),
            schemaVersion: $this->schemaVersion,
        );
    }

    public function latest(): ?AdversarialRunManifestEntry
    {
        return $this->runs[0] ?? null;
    }

    /**
     * @return array{schema_version: string, manifest: string, updated_at: float, runs: list<array<string, mixed>>}
     */
    public function toJson(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'manifest' => $this->name,
            'updated_at' => $this->updatedAt,
            'runs' => array_map(
                static fn (AdversarialRunManifestEntry $entry): array => $entry->toJson(),
                $this->runs,
            ),
        ];
    }

    /**
     * @param  list<AdversarialRunManifestEntry>  $runs
     * @return list<AdversarialRunManifestEntry>
     */
    private function retainRuns(array $runs, int $maxRuns): array
    {
        $retained = array_slice($runs, 0, $maxRuns);
        foreach ($retained as $run) {
            if ($run->totalFailures === 0) {
                return $retained;
            }
        }

        foreach ($runs as $run) {
            if ($run->totalFailures !== 0) {
                continue;
            }

            $retained[$maxRuns - 1] = $run;

            return $retained;
        }

        return $retained;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function stringKeyedPayload(array $payload, string $context): array
    {
        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                throw new EvalRunException(sprintf('Adversarial run manifest %s must use string keys.', $context));
            }
        }

        return $payload;
    }
}
