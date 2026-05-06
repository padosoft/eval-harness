<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Trend;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Padosoft\EvalHarness\ReportApi\ReportArtifactRepository;
use Padosoft\EvalHarness\ReportApi\ReportArtifactUnavailableException;
use Padosoft\EvalHarness\Reports\ReportSchema;
use Throwable;

final class DatasetTrendRepository
{
    public function __construct(
        private readonly ReportArtifactRepository $reports,
    ) {}

    /**
     * @return list<array{path: string, started_at: float, finished_at: float|null, macro_f1: float|null, total_samples: int|null, total_failures: int|null, metrics: array<string, mixed>, cohorts: list<mixed>, usage: array<string, mixed>}>
     */
    public function trend(string $datasetName, int $limit): array
    {
        $limit = max(1, min(100, $limit));
        $prefix = $this->reports->prefix();
        $basePath = $prefix === '' ? $datasetName : $prefix.'/'.$datasetName;
        $points = [];
        $disk = $this->reports->disk();

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

            $point = $this->pointFor($disk, $prefix, $path, $datasetName);
            if ($point !== null) {
                $this->keepNewestPoint($points, $point, $limit);
            }
        }

        usort($points, static fn (array $left, array $right): int => $left['started_at'] <=> $right['started_at']);

        return $points;
    }

    /**
     * @return array{path: string, started_at: float, finished_at: float|null, macro_f1: float|null, total_samples: int|null, total_failures: int|null, metrics: array<string, mixed>, cohorts: list<mixed>, usage: array<string, mixed>}|null
     */
    private function pointFor(Filesystem $disk, string $prefix, string $path, string $datasetName): ?array
    {
        try {
            $contents = $disk->get($path);
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
            'path' => $this->reports->relativePath($path, $prefix) ?? trim(str_replace('\\', '/', $path), '/'),
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

    /**
     * @param  list<array{path: string, started_at: float, finished_at: float|null, macro_f1: float|null, total_samples: int|null, total_failures: int|null, metrics: array<string, mixed>, cohorts: list<mixed>, usage: array<string, mixed>}>  $points
     * @param  array{path: string, started_at: float, finished_at: float|null, macro_f1: float|null, total_samples: int|null, total_failures: int|null, metrics: array<string, mixed>, cohorts: list<mixed>, usage: array<string, mixed>}  $point
     */
    private function keepNewestPoint(array &$points, array $point, int $limit): void
    {
        if (count($points) < $limit) {
            $points[] = $point;

            return;
        }

        $oldestIndex = 0;
        $oldestStartedAt = $points[0]['started_at'];
        foreach ($points as $index => $existing) {
            if ($existing['started_at'] < $oldestStartedAt) {
                $oldestIndex = $index;
                $oldestStartedAt = $existing['started_at'];
            }
        }

        if ($point['started_at'] > $oldestStartedAt) {
            $points[$oldestIndex] = $point;
        }
    }
}
