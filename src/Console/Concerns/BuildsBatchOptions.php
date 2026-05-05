<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console\Concerns;

use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Batches\BatchProfile;
use Padosoft\EvalHarness\Batches\BatchProfileResolver;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

trait BuildsBatchOptions
{
    /** @var list<string> */
    private const LAZY_PARALLEL_ONLY_OPTIONS = [
        'queue',
        'timeout',
        'batch-timeout',
        'result-ttl-seconds',
        'chunk-size',
        'rate-limit',
        'rate-window-seconds',
        'checkpoint-every',
    ];

    /**
     * Single source of truth for the runtime warning's flag list.
     *
     * `eval-harness:run` and `eval-harness:adversarial` skip
     * `batchOptions()` whenever `--outputs` is set, so any batch
     * flags the operator passed are silently dropped on that path.
     * `warnIfBatchFlagsIgnored()` consults this list to emit a
     * runtime warning that names the actual flags passed. Keeping
     * the list here means the two commands' warning helpers cannot
     * drift from each other.
     *
     * Note: the per-command `$description` strings (in
     * `EvalCommand` and `AdversarialCommand`) ALSO list the same
     * flags so the contract is visible from `--help`. Those help-
     * text snippets are intentionally hand-written copies — Laravel
     * command signatures are static strings and cannot be built
     * from a runtime constant. The `test_help_text_lists_every_batch_flag`
     * regression test in `BuildsBatchOptionsHelpTextTest` cross-
     * checks both descriptions against `BATCH_FLAGS` so a future
     * flag addition fails CI when only one surface gets updated.
     *
     * @var list<string>
     */
    private const BATCH_FLAGS = [
        '--batch',
        '--batch-profile',
        '--concurrency',
        '--queue',
        '--timeout',
        '--batch-timeout',
        '--result-ttl-seconds',
        '--chunk-size',
        '--rate-limit',
        '--rate-window-seconds',
        '--checkpoint-every',
    ];

    /**
     * Operators using `--outputs` score precomputed sample outputs;
     * the batch dispatch path is bypassed entirely, so any batch
     * flags (or typos in those flags) are silently dropped without
     * `BatchOptions` validation getting a chance to run. Emit a
     * single warning line listing every batch flag the operator
     * passed alongside `--outputs` so the misuse is visible at
     * runtime.
     */
    private function warnIfBatchFlagsIgnored(): void
    {
        $passed = [];
        foreach (self::BATCH_FLAGS as $flag) {
            if ($this->batchFlagWasPassed($flag)) {
                $passed[] = $flag;
            }
        }

        if ($passed === []) {
            return;
        }

        $message = sprintf(
            '<comment>Warning: Ignoring batch flags (%s) because --outputs is set; saved-output scoring bypasses the batch dispatch path.</comment>',
            implode(', ', $passed),
        );

        // Route the warning to STDERR so it does NOT pollute stdout
        // when callers run `eval-harness:run --outputs ... --json`
        // (without `--out`) and pipe stdout to a JSON parser. The
        // JSON contract documents stdout as machine-parseable; the
        // warning belongs alongside other diagnostic output.
        $output = $this->output->getOutput();
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln($message);

            return;
        }

        // Single-stream OutputInterface (BufferedOutput in
        // Artisan::call / artisan tests). When the operator also
        // passed `--json` AND is NOT redirecting stdout via `--out`,
        // suppress the warning entirely — writing it would
        // contaminate the JSON payload that programmatic callers
        // read via `Artisan::output()`, breaking the documented
        // machine-parseable contract. Operators using
        // `Artisan::call(... --json)` are programmers; they can
        // audit their own flag-passing code without the runtime
        // safety net. CLI users still get the warning on STDERR
        // via the ConsoleOutputInterface branch above.
        if ($this->jsonOutputContaminatesBuffer()) {
            return;
        }

        $this->line($message);
    }

    /**
     * Detect whether the operator explicitly passed a batch flag.
     *
     * Works in both ArgvInput (real CLI) and ArrayInput
     * (`Artisan::call(...)` / artisan testing) contexts. The
     * sentinel-based `getParameterOption()` walks the original
     * parameter source directly, so it sees explicit-default-valued
     * flags like `--batch=serial` or `--concurrency=1` that the
     * value-vs-default fallback would miss. `hasParameterOption()`
     * is checked first because it is the canonical Symfony helper
     * for this check on real CLI invocations.
     */
    private function batchFlagWasPassed(string $flag): bool
    {
        $name = ltrim($flag, '-');
        if (! $this->getDefinition()->hasOption($name)) {
            return false;
        }

        return $this->inputContainsParameterOption($flag);
    }

    /**
     * Detect the BufferedOutput + `--json` (no `--out`) combination.
     *
     * In that combination the only output stream is the
     * stdout-equivalent buffer that programmatic callers read via
     * `Artisan::output()`; writing a warning to it breaks the
     * `--json` machine-parseable contract for those callers. There
     * is no STDERR fallback on BufferedOutput, so the only safe
     * answer is to skip the warning. CLI users (ConsoleOutputInterface)
     * never reach this branch because the STDERR routing above
     * keeps stdout clean for them already.
     */
    private function jsonOutputContaminatesBuffer(): bool
    {
        if (! $this->getDefinition()->hasOption('json')) {
            return false;
        }

        if (! $this->option('json')) {
            return false;
        }

        if ($this->getDefinition()->hasOption('out')) {
            $out = $this->option('out');
            if (is_string($out) && $out !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @internal Used by `batchOptionWasProvided()` and
     *           `batchFlagWasPassed()` so cross-field reconciliation
     *           AND the runtime warning agree on what "operator
     *           passed this flag" means across input types.
     */
    private function inputContainsParameterOption(string $flag): bool
    {
        if ($this->input->hasParameterOption($flag, true)) {
            return true;
        }

        // Sentinel string returned only when the flag is absent from
        // the input source. The marker is namespaced by call to make
        // accidental collision with a real operator value impossible
        // — `getParameterOption()`'s contract returns the actual
        // string value when the flag is present, so any non-sentinel
        // result (including `null`, `''`, `'1'`, etc.) means the
        // operator passed the flag.
        $sentinel = "\0__eval_harness_param_absent__\0";

        return $this->input->getParameterOption($flag, $sentinel, true) !== $sentinel;
    }

    private function batchOptions(): BatchOptions
    {
        $profile = $this->resolveBatchProfile();
        $mode = $this->resolveBatchMode($profile);
        $modeIsSerial = $mode === BatchOptions::MODE_SERIAL;

        $concurrency = $this->resolveConcurrency($profile, $modeIsSerial);
        $queue = $this->resolveQueue($profile, $modeIsSerial);
        $timeoutSeconds = $this->resolveOptionalPositiveInt('timeout', $profile?->timeoutSeconds, $modeIsSerial);
        $waitTimeoutSeconds = $this->resolveOptionalPositiveInt('batch-timeout', $profile?->waitTimeoutSeconds, $modeIsSerial);
        $resultTtlSeconds = $this->resolveOptionalPositiveInt('result-ttl-seconds', $profile?->resultTtlSeconds, $modeIsSerial);
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
            resultTtlSeconds: $resultTtlSeconds,
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
        if ($value === null) {
            return null;
        }

        // Empty `--batch-profile=` is rejected: only the numeric
        // flags (--timeout, --batch-timeout, --chunk-size,
        // --rate-limit, --rate-window-seconds, --result-ttl-seconds,
        // --checkpoint-every) document empty-value fall-through to
        // the profile/baseline default. Treating an empty profile
        // name as "no profile" would let an unset CI variable
        // (`--batch-profile=$EVAL_PROFILE` with `EVAL_PROFILE`
        // unset) silently change batch mode and backpressure
        // without any diagnostic. Operators that do not want a
        // profile must omit the flag entirely.
        if ($value === '' && $this->batchOptionWasProvided('batch-profile')) {
            throw new EvalRunException('The --batch-profile option requires a non-empty profile name (e.g. ci, smoke, nightly). Omit the flag entirely to skip profile resolution.');
        }
        if ($value === '') {
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

        return $this->inputContainsParameterOption('--'.$name);
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
