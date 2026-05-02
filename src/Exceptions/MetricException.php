<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Exceptions;

use Padosoft\EvalHarness\Reports\EvalReport;

/**
 * Thrown when a metric implementation fails to score a sample —
 * e.g. the LLM-as-judge response was malformed JSON, the embedding
 * provider returned a wrong-dimensionality vector, or the metric's
 * scoring contract was violated (score outside [0, 1]).
 *
 * Metric failures are recoverable at the EvalEngine level: a single
 * sample's metric error is captured in the {@see EvalReport}
 * failures list rather than aborting the whole run. Callers that
 * want strict semantics can re-throw on first failure.
 */
final class MetricException extends EvalHarnessException {}
