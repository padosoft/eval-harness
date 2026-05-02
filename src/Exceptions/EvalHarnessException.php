<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Exceptions;

use RuntimeException;

/**
 * Base exception for the eval-harness package.
 *
 * Intentionally NOT marked `final` — subclasses (DatasetSchemaException,
 * MetricException, EvalRunException) extend it to give callers a single
 * `catch (EvalHarnessException $e)` clause that handles every package
 * failure mode without falling through to a generic
 * `\RuntimeException`.
 *
 * @see DatasetSchemaException
 * @see MetricException
 * @see EvalRunException
 */
class EvalHarnessException extends RuntimeException {}
