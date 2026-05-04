<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi;

use Padosoft\EvalHarness\Exceptions\EvalHarnessException;

/**
 * Thrown when a report artifact exists but its contents or metadata
 * cannot be read from storage.
 */
final class ReportArtifactUnavailableException extends EvalHarnessException {}
