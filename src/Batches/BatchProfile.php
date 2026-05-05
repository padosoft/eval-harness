<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Resolved batch profile defaults.
 *
 * Profiles are operator-facing operational presets such as `ci`, `smoke`, and
 * `nightly`. A profile only sets *defaults*; explicit CLI / programmatic
 * options always win, so profiles never lock operators in.
 */
final class BatchProfile
{
    public const NAME_CI = 'ci';

    public const NAME_SMOKE = 'smoke';

    public const NAME_NIGHTLY = 'nightly';

    /**
     * @param  positive-int|null  $concurrency
     * @param  positive-int|null  $timeoutSeconds
     * @param  positive-int|null  $waitTimeoutSeconds
     * @param  positive-int|null  $resultTtlSeconds
     * @param  positive-int|null  $chunkSize
     * @param  positive-int|null  $rateLimit
     * @param  positive-int|null  $rateWindowSeconds
     * @param  positive-int|null  $checkpointEvery
     */
    public function __construct(
        public readonly string $name,
        public readonly string $mode,
        public readonly ?int $concurrency = null,
        public readonly ?string $queue = null,
        public readonly ?int $timeoutSeconds = null,
        public readonly ?int $waitTimeoutSeconds = null,
        public readonly ?int $resultTtlSeconds = null,
        public readonly ?int $chunkSize = null,
        public readonly ?int $rateLimit = null,
        public readonly ?int $rateWindowSeconds = null,
        public readonly ?int $checkpointEvery = null,
    ) {
        if ($name === '' || trim($name) !== $name) {
            throw new EvalRunException('Batch profile name must be a non-empty string without leading or trailing whitespace.');
        }

        if (! in_array($mode, [BatchOptions::MODE_SERIAL, BatchOptions::MODE_LAZY_PARALLEL], true)) {
            throw new EvalRunException(sprintf(
                "Batch profile '%s' has unsupported mode '%s'.",
                $name,
                $mode,
            ));
        }

        $this->assertPositiveOrNull('concurrency', $concurrency);
        $this->assertPositiveOrNull('timeout_seconds', $timeoutSeconds);
        $this->assertPositiveOrNull('wait_timeout_seconds', $waitTimeoutSeconds);
        $this->assertPositiveOrNull('result_ttl_seconds', $resultTtlSeconds);
        $this->assertPositiveOrNull('chunk_size', $chunkSize);
        $this->assertPositiveOrNull('rate_limit', $rateLimit);
        $this->assertPositiveOrNull('rate_window_seconds', $rateWindowSeconds);
        $this->assertPositiveOrNull('checkpoint_every', $checkpointEvery);

        if ($queue !== null && trim($queue) === '') {
            throw new EvalRunException(sprintf("Batch profile '%s' queue must be null or a non-empty string.", $name));
        }

        if ($mode === BatchOptions::MODE_SERIAL) {
            if ($concurrency !== null && $concurrency !== 1) {
                throw new EvalRunException(sprintf("Batch profile '%s' uses serial mode and cannot set concurrency above 1.", $name));
            }

            foreach ([
                'queue' => $queue,
                'timeout_seconds' => $timeoutSeconds,
                'wait_timeout_seconds' => $waitTimeoutSeconds,
                'result_ttl_seconds' => $resultTtlSeconds,
                'chunk_size' => $chunkSize,
                'rate_limit' => $rateLimit,
                'rate_window_seconds' => $rateWindowSeconds,
                'checkpoint_every' => $checkpointEvery,
            ] as $field => $value) {
                if ($value !== null) {
                    throw new EvalRunException(sprintf("Batch profile '%s' uses serial mode and cannot set %s.", $name, $field));
                }
            }
        }

        // Mirror BatchOptions cross-validation so a profile with only
        // rate_window_seconds set fails at profile-validation time
        // instead of waiting for the trait to materialise BatchOptions.
        if ($rateWindowSeconds !== null && $rateLimit === null) {
            throw new EvalRunException(sprintf(
                "Batch profile '%s' rate_window_seconds is only meaningful with rate_limit; set rate_limit or unset rate_window_seconds.",
                $name,
            ));
        }

        // Same shape contract as BatchOptions: chunk_size cannot exceed
        // concurrency. Catching this at the profile level keeps an
        // operator from waiting until a SUT run to discover the misconfig.
        if ($chunkSize !== null && $concurrency !== null && $chunkSize > $concurrency) {
            throw new EvalRunException(sprintf(
                "Batch profile '%s' chunk_size (%d) cannot exceed concurrency (%d).",
                $name,
                $chunkSize,
                $concurrency,
            ));
        }
    }

    private function assertPositiveOrNull(string $field, ?int $value): void
    {
        if ($value === null) {
            return;
        }

        if ($value < 1) {
            throw new EvalRunException(sprintf("Batch profile '%s' %s must be greater than or equal to 1.", $this->name, $field));
        }
    }
}
