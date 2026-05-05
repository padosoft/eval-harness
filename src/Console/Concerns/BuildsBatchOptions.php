<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console\Concerns;

use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Batches\BatchProfile;
use Padosoft\EvalHarness\Batches\BatchProfileResolver;
use Padosoft\EvalHarness\Exceptions\EvalRunException;

trait BuildsBatchOptions
{
    /** @var list<string> */
    private const LAZY_PARALLEL_ONLY_OPTIONS = [
        'queue',
        'timeout',
        'batch-timeout',
        'chunk-size',
        'rate-limit',
        'rate-window-seconds',
        'checkpoint-every',
    ];

    private function batchOptions(): BatchOptions
    {
        $profile = $this->resolveBatchProfile();
        $mode = $this->resolveBatchMode($profile);
        $modeIsSerial = $mode === BatchOptions::MODE_SERIAL;

        $concurrency = $this->resolveConcurrency($profile, $modeIsSerial);
        $queue = $this->resolveQueue($profile, $modeIsSerial);
        $timeoutSeconds = $this->resolveOptionalPositiveInt('timeout', $profile?->timeoutSeconds, $modeIsSerial);
        $waitTimeoutSeconds = $this->resolveOptionalPositiveInt('batch-timeout', $profile?->waitTimeoutSeconds, $modeIsSerial);
        $chunkSize = $this->resolveOptionalPositiveInt('chunk-size', $profile?->chunkSize, $modeIsSerial);
        $rateLimit = $this->resolveOptionalPositiveInt('rate-limit', $profile?->rateLimit, $modeIsSerial);
        $rateWindowSeconds = $this->resolveOptionalPositiveInt('rate-window-seconds', $profile?->rateWindowSeconds, $modeIsSerial);
        $checkpointEvery = $this->resolveOptionalPositiveInt('checkpoint-every', $profile?->checkpointEvery, $modeIsSerial);

        // Cross-field reconciliation: keep "explicit CLI wins" intuitive
        // even when the explicit override changes the validity of an
        // inherited profile field. Without these guards, common
        // overrides like `--batch-profile=nightly --concurrency=8` or
        // `--rate-limit=none` would otherwise fail BatchOptions
        // validation because inherited fields create an inconsistent
        // combination.
        if (
            ! $modeIsSerial
            && $chunkSize !== null
            && $chunkSize > $concurrency
            && ! $this->batchOptionWasExplicit('chunk-size')
        ) {
            // Profile-inherited chunk size now exceeds the operator's
            // explicit (or baseline) concurrency cap; cap the inherited
            // chunk size so the lower concurrency override wins without
            // forcing the operator to pass --chunk-size too.
            $chunkSize = $concurrency;
        }

        if (
            $rateLimit === null
            && $rateWindowSeconds !== null
            && $this->batchOptionWasExplicit('rate-limit')
            && ! $this->batchOptionWasExplicit('rate-window-seconds')
        ) {
            // Operator explicitly cleared the rate limit (e.g.
            // `--rate-limit=none`); drop the inherited rate window
            // because rate_window_seconds is only meaningful with a
            // rate limit and BatchOptions would otherwise reject the
            // run.
            $rateWindowSeconds = null;
        }

        return new BatchOptions(
            mode: $mode,
            concurrency: $concurrency,
            queue: $queue,
            timeoutSeconds: $timeoutSeconds,
            waitTimeoutSeconds: $waitTimeoutSeconds,
            resultTtlSeconds: $modeIsSerial ? null : $profile?->resultTtlSeconds,
            profile: $profile?->name,
            chunkSize: $chunkSize,
            rateLimit: $rateLimit,
            rateWindowSeconds: $rateWindowSeconds,
            checkpointEvery: $checkpointEvery,
        );
    }

    private function resolveBatchProfile(): ?BatchProfile
    {
        if (! $this->hasOptionDefined('batch-profile')) {
            return null;
        }

        $value = $this->option('batch-profile');
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new EvalRunException('The --batch-profile option must be a non-empty string.');
        }

        /** @var BatchProfileResolver $resolver */
        $resolver = $this->laravel->make(BatchProfileResolver::class);

        return $resolver->resolve($value);
    }

    private function resolveBatchMode(?BatchProfile $profile): string
    {
        if ($this->batchOptionWasProvided('batch')) {
            $value = $this->option('batch');
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if ($profile !== null) {
            return $profile->mode;
        }

        return BatchOptions::MODE_SERIAL;
    }

    private function resolveConcurrency(?BatchProfile $profile, bool $modeIsSerial): int
    {
        if ($this->batchOptionWasProvided('concurrency')) {
            $value = $this->option('concurrency');
            if ($value !== null && $value !== '') {
                return $this->assertPositiveInt('concurrency', $value);
            }
        }

        if ($modeIsSerial) {
            return 1;
        }

        if ($profile !== null && $profile->concurrency !== null) {
            return $profile->concurrency;
        }

        return 1;
    }

    private function resolveQueue(?BatchProfile $profile, bool $modeIsSerial): ?string
    {
        if ($this->batchOptionWasProvided('queue')) {
            $value = $this->option('queue');
            // No `none`/`null` sentinel here: queue names are arbitrary
            // strings and host apps may legitimately dispatch jobs to a
            // queue literally called "none" or "null". Operators who
            // need to clear an inherited profile queue should override
            // the profile in `eval-harness.batches.profiles.*` config
            // instead of via the CLI.
            if ($value === null || $value === '') {
                // Empty: treat as not provided.
            } else {
                if (! is_string($value)) {
                    throw new EvalRunException('The --queue option must be a non-empty string.');
                }

                return $value;
            }
        }

        if ($modeIsSerial) {
            return null;
        }

        return $profile?->queue;
    }

    private function resolveOptionalPositiveInt(string $name, ?int $profileDefault, bool $modeIsSerial): ?int
    {
        if ($this->batchOptionWasProvided($name)) {
            $value = $this->option($name);
            // Operator can pass `--flag=none` (or `--flag=null`) to
            // explicitly clear a value inherited from a profile. Without
            // this sentinel, profile-numeric defaults would otherwise be
            // sticky because empty strings fall back to the profile.
            if (is_string($value) && in_array(strtolower(trim($value)), ['none', 'null'], true)) {
                return null;
            }
            if ($value !== null && $value !== '') {
                return $this->assertPositiveInt($name, $value);
            }
        }

        if ($modeIsSerial && in_array($name, self::LAZY_PARALLEL_ONLY_OPTIONS, true)) {
            return null;
        }

        return $profileDefault;
    }

    private function assertPositiveInt(string $name, mixed $value): int
    {
        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            throw new EvalRunException(sprintf('The --%s option must be a positive integer.', $name));
        }

        return (int) $value;
    }

    private function batchOptionWasProvided(string $name): bool
    {
        if (! $this->hasOptionDefined($name)) {
            return false;
        }

        return $this->input->hasParameterOption('--'.$name, true);
    }

    /**
     * `batchOptionWasProvided()` returns true even for `--flag=` (empty),
     * which the trait treats as "fall back to profile/baseline". This
     * helper distinguishes "operator passed an actual value" from "the
     * flag was on the command line but empty" so cross-field
     * reconciliation can trust the explicit-override semantic.
     */
    private function batchOptionWasExplicit(string $name): bool
    {
        if (! $this->batchOptionWasProvided($name)) {
            return false;
        }

        $value = $this->option($name);

        return $value !== null && $value !== '';
    }

    private function hasOptionDefined(string $name): bool
    {
        return $this->getDefinition()->hasOption($name);
    }
}
