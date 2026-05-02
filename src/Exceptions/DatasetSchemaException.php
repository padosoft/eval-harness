<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Exceptions;

/**
 * Thrown when a YAML / array dataset definition fails strict-schema
 * validation (missing required key, wrong type, empty samples list,
 * duplicate sample id, etc.).
 *
 * The exception message is the contract: it MUST be specific enough
 * that a human running `eval-harness:run` can edit the offending
 * golden-dataset YAML without re-reading the loader source.
 */
final class DatasetSchemaException extends EvalHarnessException {}
