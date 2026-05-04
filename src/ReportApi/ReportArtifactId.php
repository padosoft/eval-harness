<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Encodes report artifact paths into URL-safe route identifiers.
 */
final class ReportArtifactId
{
    public static function encode(string $relativePath): string
    {
        self::assertValidRelativePath($relativePath);

        return rtrim(strtr(base64_encode($relativePath), '+/', '-_'), '=');
    }

    public static function decode(string $id): string
    {
        if ($id === '' || preg_match('/^[A-Za-z0-9_-]+$/', $id) !== 1) {
            throw new EvalRunException('Report artifact id must be a non-empty URL-safe base64 string.');
        }

        $padding = strlen($id) % 4;
        $encoded = strtr($id, '-_', '+/');
        if ($padding !== 0) {
            $encoded .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($encoded, true);
        if (! is_string($decoded) || self::encode($decoded) !== $id) {
            throw new EvalRunException('Report artifact id is not a canonical report path id.');
        }

        return $decoded;
    }

    public static function assertValidRelativePath(string $relativePath): void
    {
        if ($relativePath === '' || $relativePath !== trim($relativePath)) {
            throw new EvalRunException('Report artifact path must be a non-empty relative path without leading or trailing whitespace.');
        }

        if (str_contains($relativePath, "\0") || str_contains($relativePath, '\\') || str_starts_with($relativePath, '/')) {
            throw new EvalRunException('Report artifact path must be a normalized relative path.');
        }

        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new EvalRunException('Report artifact path must not contain empty, current, or parent directory segments.');
            }
        }

        if (! str_ends_with($relativePath, '.json') && ! str_ends_with($relativePath, '.md')) {
            throw new EvalRunException('Report artifact path must point to a JSON or Markdown report.');
        }
    }
}
