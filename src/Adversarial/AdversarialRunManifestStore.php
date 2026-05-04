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
        $this->ensureDirectory($path);
        $manifestName ??= $report->datasetName;

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
        $this->ensureDirectory($path);
        $manifestName ??= $report->datasetName;
        $gate->assertConfiguration($maxDrop, $metricTargets);

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
                baseline: $manifest->latest(),
                maxDrop: $maxDrop,
                metricTargets: $metricTargets,
            );

            $this->save($path, $manifest->record($entry, maxRuns: $maxRuns));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $result;
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
}
