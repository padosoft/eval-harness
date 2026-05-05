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

    public readonly ?string $queue;

    public readonly ?string $profile;

    public function __construct(
        public readonly string $mode = self::MODE_SERIAL,
        public readonly int $concurrency = 1,
        ?string $queue = null,
        public readonly ?int $timeoutSeconds = null,
        public readonly ?int $waitTimeoutSeconds = null,
        public readonly ?int $resultTtlSeconds = null,
        ?string $profile = null,
        public readonly ?int $chunkSize = null,
        public readonly ?int $rateLimit = null,
        public readonly ?int $rateWindowSeconds = null,
        public readonly ?int $checkpointEvery = null,
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

        $normalizedQueue = $queue !== null ? trim($queue) : null;
        if ($normalizedQueue === '') {
            throw new EvalRunException('Batch queue name must be null or a non-empty string.');
        }

        $this->queue = $normalizedQueue;

        $normalizedProfile = $profile !== null ? trim($profile) : null;
        if ($profile !== null && ($normalizedProfile === '' || $normalizedProfile !== $profile)) {
            throw new EvalRunException('Batch profile name must be null or a non-empty string without leading or trailing whitespace.');
        }

        $this->profile = $normalizedProfile;

        if ($timeoutSeconds !== null && $timeoutSeconds < 1) {
            throw new EvalRunException('Queued sample timeout must be null or greater than or equal to 1 second.');
        }

        if ($waitTimeoutSeconds !== null && $waitTimeoutSeconds < 1) {
            throw new EvalRunException('Batch wait timeout must be null or greater than or equal to 1 second.');
        }

        if ($resultTtlSeconds !== null && $resultTtlSeconds < 1) {
            throw new EvalRunException('Batch result TTL must be null or greater than or equal to 1 second.');
        }

        if ($chunkSize !== null && $chunkSize < 1) {
            throw new EvalRunException('Batch chunk size must be null or greater than or equal to 1.');
        }

        if ($rateLimit !== null && $rateLimit < 1) {
            throw new EvalRunException('Batch rate limit must be null or greater than or equal to 1.');
        }

        if ($rateWindowSeconds !== null && $rateWindowSeconds < 1) {
            throw new EvalRunException('Batch rate window seconds must be null or greater than or equal to 1.');
        }

        if ($checkpointEvery !== null && $checkpointEvery < 1) {
            throw new EvalRunException('Batch checkpoint interval must be null or greater than or equal to 1.');
        }

        if ($mode === self::MODE_SERIAL) {
            if ($concurrency !== 1) {
                throw new EvalRunException('Serial batch mode requires concurrency 1.');
            }

            if ($this->queue !== null) {
                throw new EvalRunException('Serial batch mode does not use a queue name.');
            }

            if ($timeoutSeconds !== null) {
                throw new EvalRunException('Serial batch mode does not use a timeout.');
            }

            if ($waitTimeoutSeconds !== null) {
                throw new EvalRunException('Serial batch mode does not use a wait timeout.');
            }

            if ($resultTtlSeconds !== null) {
                throw new EvalRunException('Serial batch mode does not use a result TTL.');
            }

            if ($chunkSize !== null) {
                throw new EvalRunException('Serial batch mode does not use a chunk size.');
            }

            if ($rateLimit !== null) {
                throw new EvalRunException('Serial batch mode does not use a rate limit.');
            }

            if ($rateWindowSeconds !== null) {
                throw new EvalRunException('Serial batch mode does not use a rate window.');
            }

            if ($checkpointEvery !== null) {
                throw new EvalRunException('Serial batch mode does not use a checkpoint interval.');
            }
        }

        // Lazy-parallel-only cross-validation: a rate window with no rate
        // limit would otherwise be a silent no-op because LazyParallelBatch
        // only constructs a RateLimitWindow when rateLimit !== null.
        if ($rateWindowSeconds !== null && $rateLimit === null) {
            throw new EvalRunException('Batch rate window seconds is only meaningful with a rate limit; pass --rate-limit=N or unset --rate-window-seconds.');
        }
    }

    public static function serial(?string $profile = null): self
    {
        return new self(profile: $profile);
    }

    public static function lazyParallel(
        int $concurrency = 1,
        ?string $queue = null,
        ?int $timeoutSeconds = null,
        ?int $waitTimeoutSeconds = null,
        ?int $resultTtlSeconds = null,
        ?string $profile = null,
        ?int $chunkSize = null,
        ?int $rateLimit = null,
        ?int $rateWindowSeconds = null,
        ?int $checkpointEvery = null,
    ): self {
        return new self(
            mode: self::MODE_LAZY_PARALLEL,
            concurrency: $concurrency,
            queue: $queue,
            timeoutSeconds: $timeoutSeconds,
            waitTimeoutSeconds: $waitTimeoutSeconds,
            resultTtlSeconds: $resultTtlSeconds,
            profile: $profile,
            chunkSize: $chunkSize,
            rateLimit: $rateLimit,
            rateWindowSeconds: $rateWindowSeconds,
            checkpointEvery: $checkpointEvery,
        );
    }

    /**
     * Effective producer window size used by lazy-parallel batch runners.
     *
     * Defaults to {@see $concurrency} when no explicit chunk size is set.
     */
    public function effectiveChunkSize(): int
    {
        return $this->chunkSize ?? $this->concurrency;
    }
}
