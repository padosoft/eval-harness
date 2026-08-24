<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console\Concerns;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use JsonException;
use Padosoft\EvalHarness\Reports\EvalReport;

trait WritesEvalReports
{
    /**
     * Disk-relative path of the last artifact written by this command.
     *
     * Recorded so a run can refer to its own artifact afterwards — promoting
     * itself as the baseline, or excluding itself when resolving
     * `--compare=latest`, which would otherwise happily compare a run against
     * the copy of itself it had just written.
     */
    private ?string $lastWrittenArtifactPath = null;

    private function reportPayload(EvalReport $report): ?string
    {
        if (! $this->option('json')) {
            return $report->toMarkdown();
        }

        try {
            $payload = json_encode(
                $report->toJson(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $e) {
            $this->error(sprintf(
                'Failed to encode report as JSON: %s. The report contained values that cannot be serialised (most often invalid UTF-8 in actual_output or metric details).',
                $e->getMessage(),
            ));

            return null;
        }

        if (! is_string($payload)) {
            $this->error('Failed to encode report as JSON: encoder returned a non-string.');

            return null;
        }

        return $payload;
    }

    private function writeOrPrintReport(string $payload): bool
    {
        $out = $this->option('out');
        if (is_string($out) && $out !== '') {
            return $this->writeReport($out, $payload);
        }

        $this->line($payload);

        return true;
    }

    private function writeReport(string $out, string $payload): bool
    {
        return $this->writeArtifact($out, $payload, 'report');
    }

    /**
     * Write one artifact through the same path rules the report uses.
     *
     * @param  string  $label  what is being written, for the operator-facing messages
     */
    private function writeArtifact(string $out, string $payload, string $label = 'report'): bool
    {
        $rawPath = (bool) $this->option('raw-path');
        $isAbsolute = $this->isAbsolutePath($out);

        if ($rawPath || $isAbsolute) {
            $bytes = file_put_contents($out, $payload);
            if ($bytes === false) {
                $this->error(sprintf('Failed to write %s to %s', $label, $out));

                return false;
            }
            $this->info(sprintf('Wrote %d bytes to %s', $bytes, $out));

            return true;
        }

        /** @var ConfigRepository $config */
        $config = $this->laravel->make(ConfigRepository::class);
        $diskName = (string) $config->get('eval-harness.reports.disk', 'local');
        $prefix = trim((string) $config->get('eval-harness.reports.path_prefix', ''), '/');

        /** @var FilesystemFactory $filesystems */
        $filesystems = $this->laravel->make(FilesystemFactory::class);
        $disk = $filesystems->disk($diskName);

        $relativePath = $prefix === '' ? $out : $prefix.'/'.ltrim($out, '/');

        if (! $disk->put($relativePath, $payload)) {
            $this->error(sprintf(
                'Failed to write %s to disk [%s] at path [%s].',
                $label,
                $diskName,
                $relativePath,
            ));

            return false;
        }

        // Only the report itself: a command writes its comparison afterwards
        // through the same helper, and recording that path here would leave
        // "the artifact this run produced" pointing at the wrong file.
        if ($label === 'report') {
            $this->lastWrittenArtifactPath = $relativePath;
        }

        $this->info(sprintf(
            'Wrote %d bytes to disk [%s] at path [%s].',
            strlen($payload),
            $diskName,
            $relativePath,
        ));

        return true;
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }
}
