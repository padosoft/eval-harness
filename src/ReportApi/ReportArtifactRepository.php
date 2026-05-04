<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Throwable;

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

            try {
                $artifacts[] = $this->summaryArtifactFor($relativePath);
            } catch (EvalRunException) {
                continue;
            }
        }

        usort($artifacts, static fn (ReportArtifact $left, ReportArtifact $right): int => strcmp($left->path, $right->path));

        return $artifacts;
    }

    public function find(string $id): ReportArtifact
    {
        $relativePath = ReportArtifactId::decode($id);
        $disk = $this->disk();

        return $this->detailArtifactFor($disk, $relativePath);
    }

    public function contents(ReportArtifact $artifact): string
    {
        try {
            $contents = $this->disk()->get($this->storagePath($artifact->path));
        } catch (Throwable $e) {
            throw new ReportArtifactUnavailableException('Report artifact contents could not be read.', previous: $e);
        }

        if (! is_string($contents)) {
            throw new ReportArtifactUnavailableException('Report artifact contents could not be read.');
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

    private function summaryArtifactFor(string $relativePath): ReportArtifact
    {
        ReportArtifactId::assertValidRelativePath($relativePath);

        return new ReportArtifact(
            id: ReportArtifactId::encode($relativePath),
            path: $relativePath,
            format: str_ends_with($relativePath, '.json') ? 'json' : 'markdown',
            sizeBytes: null,
            lastModified: null,
        );
    }

    private function detailArtifactFor(Filesystem $disk, string $relativePath): ReportArtifact
    {
        ReportArtifactId::assertValidRelativePath($relativePath);
        $path = $this->storagePath($relativePath);
        $metadata = $this->metadataFor($disk, $path);

        return new ReportArtifact(
            id: ReportArtifactId::encode($relativePath),
            path: $relativePath,
            format: str_ends_with($relativePath, '.json') ? 'json' : 'markdown',
            sizeBytes: $metadata['size_bytes'],
            lastModified: $metadata['last_modified'],
        );
    }

    /**
     * @return array{size_bytes: int, last_modified: int}
     */
    private function metadataFor(Filesystem $disk, string $path): array
    {
        try {
            if ($disk instanceof FilesystemAdapter) {
                if (! $disk->fileExists($path)) {
                    throw new EvalRunException('Report artifact not found.');
                }
            } elseif (! $disk->exists($path)) {
                throw new EvalRunException('Report artifact not found.');
            }

            return [
                'size_bytes' => $disk->size($path),
                'last_modified' => $disk->lastModified($path),
            ];
        } catch (EvalRunException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ReportArtifactUnavailableException('Report artifact metadata could not be read.', previous: $e);
        }
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
