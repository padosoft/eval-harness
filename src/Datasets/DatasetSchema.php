<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Datasets;

/**
 * Dataset YAML contract identifiers.
 *
 * The first public schema is intentionally named v1 even while the
 * package is pre-1.0: it versions the artifact shape, not the Composer
 * release line. Future schema changes must be additive or introduce a
 * new value here.
 */
final class DatasetSchema
{
    public const FIELD = 'schema_version';

    public const VERSION = 'eval-harness.dataset.v1';

    /** @var list<string> */
    public const SUPPORTED_VERSIONS = [
        self::VERSION,
    ];

    public static function isSupported(string $version): bool
    {
        return in_array($version, self::SUPPORTED_VERSIONS, true);
    }
}
