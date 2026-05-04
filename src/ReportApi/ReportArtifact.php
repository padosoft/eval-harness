<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi;

/**
 * Read-only metadata for a stored report artifact.
 */
final class ReportArtifact
{
    public function __construct(
        public readonly string $id,
        public readonly string $path,
        public readonly string $format,
        public readonly ?int $sizeBytes,
        public readonly ?int $lastModified,
    ) {}
}
