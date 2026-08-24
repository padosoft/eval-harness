<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Regression;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Reports\ReportSchema;
use Throwable;

/**
 * The pointer that says which stored report a dataset is measured against.
 *
 * ## Why a pointer and not a copy
 *
 * A baseline here is one small JSON file naming a report artifact that already
 * exists on the reports disk. Nothing is duplicated, so a baseline cannot drift
 * from the run it claims to describe, and promoting one is reversible by
 * rewriting a single line.
 *
 * ## Why on disk and not in a database
 *
 * This is the design decision that separates this package from the tools it
 * competes with, and it is worth being explicit about. When runs and baselines
 * live only in a database, the baseline lives in *one* database — whoever
 * promoted it — and CI, which starts from an empty schema every time, has no
 * history at all. That gap is exactly the hole those tools then sell a hosted
 * service to fill.
 *
 * Here the artifact and its pointer are files. They travel in a CI artifact,
 * they can be committed next to the dataset that produced them, and a
 * comparison in CI reads the same bytes the developer read locally. An optional
 * database index can be layered on top for querying — but the file stays the
 * source of truth, and the index is rebuildable from it.
 */
final class BaselineStore
{
    private const BASELINE_DIRECTORY = 'baselines';

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Record the given stored report as the baseline for a dataset.
     *
     * @param  array<string, mixed>  $reportPayload  decoded report, used for the summary fields
     * @return array<string, mixed> the stored pointer
     */
    public function promote(string $dataset, string $reportPath, array $reportPayload, ?string $promotedAt = null): array
    {
        $pointer = [
            'schema_version' => RegressionSchema::BASELINE_VERSION,
            'dataset' => $dataset,
            'report_path' => $reportPath,
            'promoted_at' => $promotedAt ?? gmdate('c'),
            'summary' => [
                'macro_f1' => $reportPayload['macro_f1'] ?? null,
                'pass_rate' => $reportPayload['pass_rate'] ?? null,
                'repetitions' => $reportPayload['repetitions'] ?? null,
                'total_executions' => $reportPayload['total_executions'] ?? null,
                'total_samples' => $reportPayload['total_samples'] ?? null,
                'total_failures' => $reportPayload['total_failures'] ?? null,
                'finished_at' => $reportPayload['finished_at'] ?? null,
            ],
        ];

        $encoded = json_encode($pointer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            throw new EvalRunException(sprintf("Baseline pointer for dataset '%s' could not be encoded.", $dataset));
        }

        try {
            $written = $this->disk()->put($this->pointerPath($dataset), $encoded);
        } catch (Throwable $e) {
            throw new EvalRunException(
                sprintf("Baseline pointer for dataset '%s' could not be written: %s", $dataset, $e->getMessage()),
                previous: $e,
            );
        }

        // Laravel filesystem adapters return false rather than throwing on a
        // rejected write. Announcing a promotion that never landed would leave
        // the next run comparing against the wrong baseline and believing it
        // was the right one.
        if ($written === false) {
            throw new EvalRunException(sprintf(
                "Baseline pointer for dataset '%s' could not be written to the reports disk.",
                $dataset,
            ));
        }

        return $pointer;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pointer(string $dataset): ?array
    {
        $path = $this->pointerPath($dataset);
        $disk = $this->disk();

        try {
            if (! $disk->exists($path)) {
                return null;
            }

            $contents = $disk->get($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The decoded report a dataset's baseline points at.
     *
     * A pointer whose report has since been deleted returns null rather than
     * throwing: a missing baseline must degrade a run to "nothing to compare
     * against", never break it. Losing the reference is not a reason to fail a
     * build that was otherwise fine.
     *
     * @return array<string, mixed>|null
     */
    public function report(string $dataset): ?array
    {
        $pointer = $this->pointer($dataset);
        $path = $pointer['report_path'] ?? null;

        if (! is_string($path) || $path === '') {
            return null;
        }

        return $this->readReport($path);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readReport(string $path): ?array
    {
        try {
            $disk = $this->disk();

            if (! $disk->exists($path)) {
                return null;
            }

            $contents = $disk->get($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    public function clear(string $dataset): bool
    {
        $path = $this->pointerPath($dataset);

        try {
            $disk = $this->disk();

            if (! $disk->exists($path)) {
                return false;
            }

            return $disk->delete($path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The most recent stored report for a dataset, excluding one path.
     *
     * Used by `--compare=latest`, which is the reference people reach for
     * before they have promoted anything: "is this worse than the last run I
     * did?" is a question that needs no ceremony.
     */
    public function latestReportPath(string $dataset, ?string $excludePath = null): ?string
    {
        $disk = $this->disk();
        $prefix = $this->prefix();

        try {
            $paths = $disk->allFiles($prefix === '' ? null : $prefix);
        } catch (Throwable) {
            return null;
        }

        $candidates = [];

        foreach ($paths as $path) {
            if (! is_string($path) || ! str_ends_with($path, '.json')) {
                continue;
            }

            if ($path === $excludePath || str_contains($path, '/'.self::BASELINE_DIRECTORY.'/')) {
                continue;
            }

            $report = $this->readReport($path);

            if ($report === null || ($report['dataset'] ?? null) !== $dataset) {
                continue;
            }

            // A comparison payload written by --comparison-out sits in the same
            // prefix, names the same dataset, and is newer than the report it
            // describes — so without this it would win "latest" and, having no
            // sample_aggregates, make the next gate see zero regressions and
            // pass. Only artifacts that declare the report contract qualify.
            if (($report['schema_version'] ?? null) !== ReportSchema::VERSION) {
                continue;
            }

            $finishedAt = $report['finished_at'] ?? null;
            $candidates[$path] = is_int($finishedAt) || is_float($finishedAt)
                ? (float) $finishedAt
                : (float) $this->lastModified($disk, $path);
        }

        if ($candidates === []) {
            return null;
        }

        arsort($candidates);

        return (string) array_key_first($candidates);
    }

    public function pointerPath(string $dataset): string
    {
        $prefix = $this->prefix();
        $file = self::BASELINE_DIRECTORY.'/'.$this->slug($dataset).'.json';

        return $prefix === '' ? $file : $prefix.'/'.$file;
    }

    /**
     * Dataset names are dotted identifiers, but they arrive from config and
     * from the CLI, so the filename is built from an allow-list rather than by
     * escaping: anything outside `[A-Za-z0-9._-]` becomes an underscore, and a
     * leading dot cannot survive. No input can walk out of the baselines
     * directory.
     */
    private function slug(string $dataset): string
    {
        $slug = preg_replace('/[^A-Za-z0-9._-]/', '_', $dataset) ?? '';
        $slug = ltrim($slug, '.');

        return $slug === '' ? 'dataset' : $slug;
    }

    private function disk(): Filesystem
    {
        $disk = $this->config->get('eval-harness.reports.disk', 'local');

        return $this->filesystems->disk(is_string($disk) ? $disk : 'local');
    }

    private function prefix(): string
    {
        $prefix = $this->config->get('eval-harness.reports.path_prefix', 'eval-harness/reports');

        return is_string($prefix) ? trim($prefix, '/') : '';
    }

    private function lastModified(Filesystem $disk, string $path): int
    {
        try {
            return (int) $disk->lastModified($path);
        } catch (Throwable) {
            return 0;
        }
    }
}
