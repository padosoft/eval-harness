<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Reads report artifacts from the configured reports disk and prefix.
 */
final class ReportArtifactRepository
{
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return list<ReportArtifact>
     */
    public function all(): array
    {
        $disk = $this->disk();
        $prefix = $this->prefix();
        $artifacts = [];

        foreach ($disk->allFiles($prefix === '' ? null : $prefix) as $path) {
            if (! is_string($path)) {
                continue;
            }

            $relativePath = $this->relativePath($path, $prefix);
            if ($relativePath === null || ! $this->isReportPath($relativePath)) {
                continue;
            }

            $artifacts[] = $this->artifactFor($disk, $relativePath);
        }

        usort($artifacts, static fn (ReportArtifact $left, ReportArtifact $right): int => strcmp($left->path, $right->path));

        return $artifacts;
    }

    public function find(string $id): ReportArtifact
    {
        $relativePath = ReportArtifactId::decode($id);
        $disk = $this->disk();
        $path = $this->storagePath($relativePath);

        if (! $disk->exists($path)) {
            throw new EvalRunException('Report artifact not found.');
        }

        return $this->artifactFor($disk, $relativePath);
    }

    public function contents(ReportArtifact $artifact): string
    {
        $contents = $this->disk()->get($this->storagePath($artifact->path));
        if (! is_string($contents)) {
            throw new EvalRunException('Report artifact contents could not be read.');
        }

        return $contents;
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

        return trim(str_replace('\\', '/', $prefix), '/');
    }

    private function storagePath(string $relativePath): string
    {
        ReportArtifactId::assertValidRelativePath($relativePath);

        $prefix = $this->prefix();

        return $prefix === '' ? $relativePath : $prefix.'/'.$relativePath;
    }

    private function artifactFor(Filesystem $disk, string $relativePath): ReportArtifact
    {
        ReportArtifactId::assertValidRelativePath($relativePath);
        $path = $this->storagePath($relativePath);

        return new ReportArtifact(
            id: ReportArtifactId::encode($relativePath),
            path: $relativePath,
            format: str_ends_with($relativePath, '.json') ? 'json' : 'markdown',
            sizeBytes: $disk->size($path),
            lastModified: $disk->lastModified($path),
        );
    }

    private function relativePath(string $path, string $prefix): ?string
    {
        $normalized = trim(str_replace('\\', '/', $path), '/');
        if ($prefix === '') {
            return $normalized;
        }

        if ($normalized === $prefix || ! str_starts_with($normalized, $prefix.'/')) {
            return null;
        }

        return substr($normalized, strlen($prefix) + 1);
    }

    private function isReportPath(string $relativePath): bool
    {
        return str_ends_with($relativePath, '.json') || str_ends_with($relativePath, '.md');
    }
}
