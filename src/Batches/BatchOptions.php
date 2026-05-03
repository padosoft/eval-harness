<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Runtime options for sample batch execution.
 *
 * Only serial execution is implemented in this slice. Queue-backed
 * lazy parallel execution will reuse the same option object so CLI
 * validation and docs do not drift when jobs are introduced.
 */
final class BatchOptions
{
    public const MODE_SERIAL = 'serial';

    public function __construct(
        public readonly string $mode = self::MODE_SERIAL,
        public readonly int $concurrency = 1,
        public readonly ?string $queue = null,
        public readonly ?int $timeoutSeconds = null,
    ) {
        if ($mode !== self::MODE_SERIAL) {
            throw new EvalRunException(sprintf(
                "Unsupported batch mode '%s'. Supported modes: %s.",
                $mode,
                self::MODE_SERIAL,
            ));
        }

        if ($concurrency < 1) {
            throw new EvalRunException('Batch concurrency must be greater than or equal to 1.');
        }

        if ($concurrency !== 1) {
            throw new EvalRunException('Serial batch mode requires concurrency 1.');
        }

        if ($queue !== null && trim($queue) === '') {
            throw new EvalRunException('Batch queue name must be null or a non-empty string.');
        }

        if ($queue !== null) {
            throw new EvalRunException('Serial batch mode does not use a queue name.');
        }

        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new EvalRunException('Batch timeout must be null or greater than or equal to 1 second.');
        }
    }

    public static function serial(): self
    {
        return new self;
    }
}
