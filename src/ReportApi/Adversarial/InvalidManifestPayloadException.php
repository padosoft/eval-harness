<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Adversarial;

use RuntimeException;

/**
 * Raised when a discovered adversarial manifest exists but its JSON
 * payload is malformed or violates the manifest schema.
 */
final class InvalidManifestPayloadException extends RuntimeException {}
