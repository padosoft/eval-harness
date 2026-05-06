<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Trend;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Padosoft\EvalHarness\ReportApi\ReportArtifactUnavailableException;
use Padosoft\EvalHarness\Reports\ReportSchema;
use Throwable;

final class DatasetTrendRepository
{
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return list<array{path: string, started_at: float, finished_at: float|null, macro_f1: float|null, total_samples: int|null, total_failures: int|null, metrics: array<string, mixed>, cohorts: list<mixed>, usage: array<string, mixed>}>
     */
    public function trend(string $datasetName, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $prefix = $this->prefix();
        $basePath = $prefix === '' ? $datasetName : $prefix.'/'.$datasetName;
        $points = [];
        $disk = $this->disk();

        try {
            if ($disk instanceof FilesystemAdapter) {
                if (! $disk->directoryExists($basePath)) {
                    return [];
                }
            } elseif (! $disk->exists($basePath)) {
                return [];
            }

            $paths = $disk->files($basePath);
        } catch (Throwable $e) {
            throw new ReportArtifactUnavailableException('Dataset trend listing could not be read.', previous: $e);
        }

        foreach ($paths as $path) {
            if (! is_string($path) || ! str_ends_with($path, '.json')) {
                continue;
            }

            $point = $this->pointFor($path, $datasetName);
            if ($point !== null) {
                $points[] = $point;
            }
        }

        usort($points, static fn (array $left, array $right): int => $left['started_at'] <=> $right['started_at']);

        return array_slice($points, -$limit);
    }

    /**
     * @return array{path: string, started_at: float, finished_at: float|null, macro_f1: float|null, total_samples: int|null, total_failures: int|null, metrics: array<string, mixed>, cohorts: list<mixed>, usage: array<string, mixed>}|null
     */
    private function pointFor(string $path, string $datasetName): ?array
    {
        try {
            $contents = $this->disk()->get($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($contents)) {
            return null;
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (
            ! is_array($payload)
            || ($payload['schema_version'] ?? null) !== ReportSchema::VERSION
            || ($payload['dataset'] ?? null) !== $datasetName
        ) {
            return null;
        }

        $startedAt = $payload['started_at'] ?? null;
        if (! is_int($startedAt) && ! is_float($startedAt)) {
            return null;
        }

        $finishedAt = $payload['finished_at'] ?? null;
        $macroF1 = $payload['macro_f1'] ?? null;
        $totalSamples = $payload['total_samples'] ?? null;
        $totalFailures = $payload['total_failures'] ?? null;
        $metrics = $payload['metrics'] ?? [];
        $cohorts = $payload['cohorts'] ?? [];
        $usage = $payload['usage'] ?? [];

        return [
            'path' => $this->relativePath($path),
            'started_at' => (float) $startedAt,
            'finished_at' => is_int($finishedAt) || is_float($finishedAt) ? (float) $finishedAt : null,
            'macro_f1' => is_int($macroF1) || is_float($macroF1) ? (float) $macroF1 : null,
            'total_samples' => is_int($totalSamples) ? $totalSamples : null,
            'total_failures' => is_int($totalFailures) ? $totalFailures : null,
            'metrics' => is_array($metrics) ? $metrics : [],
            'cohorts' => is_array($cohorts) && array_is_list($cohorts) ? $cohorts : [],
            'usage' => is_array($usage) ? $usage : [],
        ];
    }

    private function disk(): Filesystem
    {
        $diskName = $this->config->get('eval-harness.reports.disk', 'local');
        $diskName = is_string($diskName) && trim($diskName) !== '' ? trim($diskName) : 'local';

        return $this->filesystems->disk($diskName);
    }

    private function prefix(): string
    {
        $prefix = $this->config->get('eval-harness.reports.path_prefix', 'eval-harness/reports');
        if (! is_string($prefix)) {
            return 'eval-harness/reports';
        }

        return trim(trim(str_replace('\\', '/', $prefix)), '/');
    }

    private function relativePath(string $path): string
    {
        $prefix = $this->prefix();
        $normalized = trim(str_replace('\\', '/', $path), '/');

        return $prefix === '' || ! str_starts_with($normalized, $prefix.'/')
            ? $normalized
            : substr($normalized, strlen($prefix) + 1);
    }
}
