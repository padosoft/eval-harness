<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Adversarial;

use JsonException;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Reports\EvalReport;

/**
 * File-backed retention store for adversarial run manifests.
 */
final class AdversarialRunManifestStore
{
    public function record(
        string $path,
        EvalReport $report,
        int $maxRuns = 10,
        ?string $manifestName = null,
        ?string $runId = null,
    ): AdversarialRunManifest {
        $this->assertPath($path);
        $this->assertRetention($maxRuns);
        $manifestName ??= $report->datasetName;
        $this->assertManifestName($manifestName);
        $this->assertRunId($runId);
        $this->ensureDirectory($path);

        $lock = $this->openLock($path);
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new EvalRunException(sprintf("Failed to lock adversarial run manifest '%s'.", $path));
            }

            $manifest = $this->load($path);
            if ($manifest !== null && $manifest->name !== $manifestName) {
                throw new EvalRunException(sprintf(
                    "Adversarial run manifest '%s' belongs to manifest '%s', not '%s'.",
                    $path,
                    $manifest->name,
                    $manifestName,
                ));
            }

            $manifest ??= AdversarialRunManifest::empty($manifestName);
            $manifest = $manifest->record(
                AdversarialRunManifestEntry::fromReport($report, $runId),
                maxRuns: $maxRuns,
            );

            $this->save($path, $manifest);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $manifest;
    }

    /**
     * @param  list<string>  $metricTargets
     */
    public function recordWithRegressionGate(
        string $path,
        EvalReport $report,
        AdversarialRegressionGate $gate,
        float $maxDrop,
        array $metricTargets = [],
        int $maxRuns = 10,
        ?string $manifestName = null,
        ?string $runId = null,
    ): AdversarialRegressionGateResult {
        $this->assertPath($path);
        $manifestName ??= $report->datasetName;
        $this->assertManifestName($manifestName);
        $this->assertRunId($runId);
        $gate->assertConfiguration($maxDrop, $metricTargets);
        $this->assertRetention($maxRuns);
        $this->ensureDirectory($path);

        $lock = $this->openLock($path);
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new EvalRunException(sprintf("Failed to lock adversarial run manifest '%s'.", $path));
            }

            $manifest = $this->load($path);
            if ($manifest !== null && $manifest->name !== $manifestName) {
                throw new EvalRunException(sprintf(
                    "Adversarial run manifest '%s' belongs to manifest '%s', not '%s'.",
                    $path,
                    $manifest->name,
                    $manifestName,
                ));
            }

            $manifest ??= AdversarialRunManifest::empty($manifestName);
            $entry = AdversarialRunManifestEntry::fromReport($report, $runId);
            $result = $gate->evaluate(
                current: $entry,
                baseline: $this->latestCompatibleBaseline($manifest, $entry),
                maxDrop: $maxDrop,
                metricTargets: $metricTargets,
            );

            if ($this->shouldRecordRegressionGateResult($entry, $result)) {
                $manifest = $manifest->record($entry, maxRuns: $maxRuns);
                $this->save($path, $manifest);

                return $this->withRecordingStatus(
                    $result,
                    recorded: $this->manifestContainsRun($manifest, $entry->runId),
                );
            }

            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function load(string $path): ?AdversarialRunManifest
    {
        $this->assertPath($path);

        if (! is_file($path)) {
            return null;
        }

        if (! is_readable($path)) {
            throw new EvalRunException(sprintf("Adversarial run manifest '%s' is not readable.", $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new EvalRunException(sprintf("Adversarial run manifest '%s' could not be read.", $path));
        }

        try {
            $payload = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new EvalRunException(sprintf(
                "Adversarial run manifest '%s' contains invalid JSON: %s.",
                $path,
                $e->getMessage(),
            ), previous: $e);
        }

        if (! is_array($payload)) {
            throw new EvalRunException(sprintf("Adversarial run manifest '%s' must contain a JSON object.", $path));
        }

        return AdversarialRunManifest::fromJson($this->stringKeyedPayload($payload, $path));
    }

    public function save(string $path, AdversarialRunManifest $manifest): void
    {
        $this->assertPath($path);

        try {
            $payload = json_encode(
                $manifest->toJson(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            throw new EvalRunException(sprintf(
                "Failed to encode adversarial run manifest '%s': %s.",
                $path,
                $e->getMessage(),
            ), previous: $e);
        }

        if (! is_string($payload)) {
            throw new EvalRunException(sprintf("Failed to encode adversarial run manifest '%s': encoder returned a non-string.", $path));
        }

        $directory = $this->ensureDirectory($path);

        $tempPath = tempnam($directory, basename($path).'.tmp.');
        if ($tempPath === false) {
            throw new EvalRunException(sprintf("Failed to create a temporary file for adversarial run manifest '%s'.", $path));
        }

        try {
            if (file_put_contents($tempPath, $payload."\n", LOCK_EX) === false) {
                throw new EvalRunException(sprintf("Failed to write temporary adversarial run manifest '%s'.", $tempPath));
            }

            if (! rename($tempPath, $path)) {
                throw new EvalRunException(sprintf("Failed to replace adversarial run manifest '%s'.", $path));
            }
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function assertPath(string $path): void
    {
        if ($path === '' || $path !== trim($path)) {
            throw new EvalRunException('Adversarial run manifest path must be a non-empty string without leading or trailing whitespace.');
        }
    }

    private function assertManifestName(string $manifestName): void
    {
        if ($manifestName === '' || $manifestName !== trim($manifestName)) {
            throw new EvalRunException('Adversarial run manifest name must be a non-empty string without leading or trailing whitespace.');
        }
    }

    private function assertRunId(?string $runId): void
    {
        if ($runId !== null && ($runId === '' || $runId !== trim($runId))) {
            throw new EvalRunException('Adversarial run manifest entry run_id must be a non-empty string without leading or trailing whitespace.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stringKeyedPayload(array $payload, string $path): array
    {
        foreach (array_keys($payload) as $key) {
            if (! is_string($key)) {
                throw new EvalRunException(sprintf("Adversarial run manifest '%s' must use string keys.", $path));
            }
        }

        return $payload;
    }

    private function ensureDirectory(string $path): string
    {
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new EvalRunException(sprintf("Failed to create adversarial run manifest directory '%s'.", $directory));
        }

        return $directory;
    }

    /**
     * @return resource
     */
    private function openLock(string $path): mixed
    {
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) {
            throw new EvalRunException(sprintf("Failed to open adversarial run manifest lock '%s.lock'.", $path));
        }

        return $lock;
    }

    private function latestCompatibleBaseline(
        AdversarialRunManifest $manifest,
        AdversarialRunManifestEntry $current,
    ): ?AdversarialRunManifestEntry {
        $currentSignature = AdversarialRunSliceSignature::fromEntry($current);

        foreach ($manifest->runs as $baseline) {
            if ($baseline->runId === $current->runId) {
                continue;
            }

            if ($baseline->totalFailures > 0) {
                continue;
            }

            if (AdversarialRunSliceSignature::fromEntry($baseline) !== $currentSignature) {
                continue;
            }

            return $baseline;
        }

        return null;
    }

    private function shouldRecordRegressionGateResult(
        AdversarialRunManifestEntry $entry,
        AdversarialRegressionGateResult $result,
    ): bool {
        if ($result->failed()) {
            return false;
        }

        if ($entry->totalFailures > 0) {
            return false;
        }

        foreach ($result->checks as $check) {
            if ($check->status === AdversarialRegressionGateCheck::STATUS_MISSING_VALUE) {
                return false;
            }
        }

        return true;
    }

    private function manifestContainsRun(AdversarialRunManifest $manifest, string $runId): bool
    {
        foreach ($manifest->runs as $run) {
            if ($run->runId === $runId) {
                return true;
            }
        }

        return false;
    }

    private function withRecordingStatus(
        AdversarialRegressionGateResult $result,
        bool $recorded,
    ): AdversarialRegressionGateResult {
        return new AdversarialRegressionGateResult(
            status: $result->status,
            currentRunId: $result->currentRunId,
            baselineRunId: $result->baselineRunId,
            checks: $result->checks,
            recorded: $recorded,
        );
    }

    private function assertRetention(int $maxRuns): void
    {
        if ($maxRuns < 1) {
            throw new EvalRunException('Adversarial run manifest retention must keep at least one run.');
        }
    }
}
