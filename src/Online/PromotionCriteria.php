<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Which recorded interactions are worth promoting.
 *
 * Defaults to the failures, because a dataset of rows the pipeline already got
 * right is a dataset that can only ever stay green. Promoting passing rows is
 * still useful — pinning behaviour you want to keep — so it is available, and
 * it is not the default.
 */
final class PromotionCriteria
{
    public const DEFAULT_LIMIT = 50;

    public function __construct(
        public readonly bool $failingOnly = true,
        public readonly ?float $maxScore = null,
        public readonly ?int $sinceDays = null,
        public readonly int $limit = self::DEFAULT_LIMIT,
    ) {
        if ($maxScore !== null && ($maxScore < 0.0 || $maxScore > 1.0)) {
            throw new EvalRunException(sprintf('A maximum score must be between 0 and 1; got %s.', var_export($maxScore, true)));
        }

        if ($sinceDays !== null && $sinceDays < 1) {
            throw new EvalRunException(sprintf('A promotion window must be at least one day; got %d.', $sinceDays));
        }

        if ($limit < 1) {
            throw new EvalRunException(sprintf('A promotion limit must be at least 1; got %d.', $limit));
        }
    }
}
