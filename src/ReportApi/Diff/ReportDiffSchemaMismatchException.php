<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Diff;

use Padosoft\EvalHarness\Exceptions\EvalHarnessException;

/**
 * Raised when a report payload submitted for diff carries a
 * `schema_version` that does not match `ReportSchema::VERSION`.
 *
 * Surfaces as HTTP 422 to the API caller.
 */
final class ReportDiffSchemaMismatchException extends EvalHarnessException {}
