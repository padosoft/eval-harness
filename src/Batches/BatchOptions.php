<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Runtime options for sample batch execution.
 */
final class BatchOptions
{
    public const MODE_SERIAL = 'serial';

    public const MODE_LAZY_PARALLEL = 'lazy-parallel';

    /** @var list<string> */
    private const SUPPORTED_MODES = [
        self::MODE_SERIAL,
        self::MODE_LAZY_PARALLEL,
    ];

    public function __construct(
        public readonly string $mode = self::MODE_SERIAL,
        public readonly int $concurrency = 1,
        public readonly ?string $queue = null,
        public readonly ?int $timeoutSeconds = null,
        public readonly ?int $waitTimeoutSeconds = null,
    ) {
        if (! in_array($mode, self::SUPPORTED_MODES, true)) {
            throw new EvalRunException(sprintf(
                "Unsupported batch mode '%s'. Supported modes: %s.",
                $mode,
                implode(', ', self::SUPPORTED_MODES),
            ));
        }

        if ($concurrency < 1) {
            throw new EvalRunException('Batch concurrency must be greater than or equal to 1.');
        }

        if ($queue !== null && trim($queue) === '') {
            throw new EvalRunException('Batch queue name must be null or a non-empty string.');
        }

        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new EvalRunException('Batch timeout must be null or greater than or equal to 1 second.');
        }

        if ($waitTimeoutSeconds !== null && $waitTimeoutSeconds < 1) {
            throw new EvalRunException('Batch wait timeout must be null or greater than or equal to 1 second.');
        }

        if ($mode === self::MODE_SERIAL) {
            if ($concurrency !== 1) {
                throw new EvalRunException('Serial batch mode requires concurrency 1.');
            }

            if ($queue !== null) {
                throw new EvalRunException('Serial batch mode does not use a queue name.');
            }

            if ($timeoutSeconds !== null) {
                throw new EvalRunException('Serial batch mode does not use a timeout.');
            }

            if ($waitTimeoutSeconds !== null) {
                throw new EvalRunException('Serial batch mode does not use a wait timeout.');
            }
        }
    }

    public static function serial(): self
    {
        return new self;
    }

    public static function lazyParallel(
        int $concurrency = 1,
        ?string $queue = null,
        ?int $timeoutSeconds = null,
        ?int $waitTimeoutSeconds = null,
    ): self {
        return new self(
            mode: self::MODE_LAZY_PARALLEL,
            concurrency: $concurrency,
            queue: $queue,
            timeoutSeconds: $timeoutSeconds,
            waitTimeoutSeconds: $waitTimeoutSeconds,
        );
    }
}
