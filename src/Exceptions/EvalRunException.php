<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Exceptions;

/**
 * Thrown when an eval run cannot proceed at all — dataset name
 * unregistered, system-under-test callable returned a non-string,
 * or no metrics were declared.
 */
final class EvalRunException extends EvalHarnessException {}
