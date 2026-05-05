<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Batches;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Resolves named batch profiles ({@see BatchProfile}) from built-in defaults
 * plus optional `eval-harness.batches.profiles.*` config overrides.
 *
 * Profiles are operator presets that fill in defaults for batch options;
 * explicit CLI/programmatic options always override profile defaults.
 */
final class BatchProfileResolver
{
    /**
     * Built-in profiles. Host apps can override individual fields per profile
     * or register additional named profiles via the
     * `eval-harness.batches.profiles.<name>` config map; built-in defaults are
     * applied first and config overrides are layered on top.
     *
     * @var array<string, array{
     *     mode: string,
     *     concurrency?: int|null,
     *     queue?: string|null,
     *     timeout_seconds?: int|null,
     *     wait_timeout_seconds?: int|null,
     *     result_ttl_seconds?: int|null,
     *     chunk_size?: int|null,
     *     rate_limit?: int|null,
     *     rate_window_seconds?: int|null,
     *     checkpoint_every?: int|null,
     * }>
     */
    public const BUILT_IN_PROFILES = [
        BatchProfile::NAME_CI => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 4,
            'timeout_seconds' => 30,
            'wait_timeout_seconds' => 120,
            'chunk_size' => 4,
            'checkpoint_every' => 25,
        ],
        BatchProfile::NAME_SMOKE => [
            'mode' => BatchOptions::MODE_SERIAL,
        ],
        BatchProfile::NAME_NIGHTLY => [
            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
            'concurrency' => 16,
            'timeout_seconds' => 120,
            'wait_timeout_seconds' => 600,
            'chunk_size' => 16,
            'rate_limit' => 60,
            'rate_window_seconds' => 60,
            'checkpoint_every' => 100,
        ],
    ];

    /**
     * @var array<string, BatchProfile>
     */
    private array $profiles;

    public function __construct(?ConfigRepository $config = null)
    {
        $this->profiles = $this->buildProfiles($config);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->profiles);
    }

    public function resolve(string $name): BatchProfile
    {
        if ($name === '' || trim($name) !== $name) {
            throw new EvalRunException('Batch profile name must be a non-empty string without leading or trailing whitespace.');
        }

        if (! array_key_exists($name, $this->profiles)) {
            throw new EvalRunException(sprintf(
                "Unknown batch profile '%s'. Available profiles: %s.",
                $name,
                implode(', ', $this->names()),
            ));
        }

        return $this->profiles[$name];
    }

    /** @var list<string> */
    private const LAZY_ONLY_PROFILE_FIELDS = [
        'concurrency',
        'queue',
        'timeout_seconds',
        'wait_timeout_seconds',
        'result_ttl_seconds',
        'chunk_size',
        'rate_limit',
        'rate_window_seconds',
        'checkpoint_every',
    ];

    /**
     * Allowlist of every key a profile definition may carry. Anything
     * outside this list is rejected so a typo (e.g. `concurency`,
     * `checkpointEvery`) surfaces loudly instead of being silently
     * dropped while the built-in default keeps winning.
     *
     * @var list<string>
     */
    private const KNOWN_PROFILE_KEYS = [
        'mode',
        'concurrency',
        'queue',
        'timeout_seconds',
        'wait_timeout_seconds',
        'result_ttl_seconds',
        'chunk_size',
        'rate_limit',
        'rate_window_seconds',
        'checkpoint_every',
    ];

    /**
     * @return array<string, BatchProfile>
     */
    private function buildProfiles(?ConfigRepository $config): array
    {
        /** @var array<string, mixed> $overrides */
        $overrides = [];
        if ($config !== null) {
            // Distinguish "key absent" (no overrides) from "key
            // explicitly null" (env-backed config like
            // `'profiles' => env('FOO')` where the env var is unset).
            // `Repository::has()` cannot reliably detect this on every
            // Laravel/config-source combination — Arr::has reports
            // false for null leaf values in some flows. Use a unique
            // sentinel default so the round-trip through `get()`
            // returns the sentinel ONLY when the path is not present
            // at any level. The operator's intended null override
            // surfaces as an explicit null and gets rejected loudly;
            // falling back to built-in defaults silently would make
            // intended host-app overrides disappear in production.
            $sentinel = "\0__eval_harness_profiles_absent__\0";
            $value = $config->get('eval-harness.batches.profiles', $sentinel);
            if ($value !== $sentinel) {
                if ($value === null) {
                    throw new EvalRunException(
                        'eval-harness.batches.profiles is null. Set it to a map of profile-name => override-array, or remove the key to use the built-in defaults.',
                    );
                }
                if (! is_array($value)) {
                    throw new EvalRunException(sprintf(
                        'eval-harness.batches.profiles must be a map of profile-name => override-array, got %s.',
                        get_debug_type($value),
                    ));
                }
                $overrides = $value;
            }
        }

        $merged = self::BUILT_IN_PROFILES;
        foreach ($overrides as $name => $definition) {
            if (! is_string($name) || $name === '' || trim($name) !== $name) {
                throw new EvalRunException('Batch profile names must be non-empty strings without leading or trailing whitespace.');
            }

            if (! is_array($definition)) {
                throw new EvalRunException(sprintf("Batch profile '%s' override must be an array.", $name));
            }

            $existing = $merged[$name] ?? ['mode' => BatchOptions::MODE_SERIAL];

            // When a host-app override flips the resolved mode to serial,
            // drop inherited lazy-only fields from the built-in so the
            // merged result remains a valid serial profile. The override
            // can still set any field explicitly; the explicit value wins
            // and any lazy-only field set on a serial profile will surface
            // through BatchProfile validation as before.
            $existingMode = is_string($existing['mode'] ?? null) ? $existing['mode'] : BatchOptions::MODE_SERIAL;
            $resolvedMode = is_string($definition['mode'] ?? null) ? $definition['mode'] : $existingMode;
            if ($resolvedMode === BatchOptions::MODE_SERIAL && $existingMode !== BatchOptions::MODE_SERIAL) {
                foreach (self::LAZY_ONLY_PROFILE_FIELDS as $field) {
                    if (! array_key_exists($field, $definition)) {
                        unset($existing[$field]);
                    }
                }
            }

            $merged[$name] = array_replace($existing, $definition);
        }

        $resolved = [];
        foreach ($merged as $name => $definition) {
            $resolved[$name] = $this->buildProfile((string) $name, $definition);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function buildProfile(string $name, array $definition): BatchProfile
    {
        $unknownKeys = array_diff(array_keys($definition), self::KNOWN_PROFILE_KEYS);
        if ($unknownKeys !== []) {
            throw new EvalRunException(sprintf(
                "Batch profile '%s' has unknown key(s): %s. Known keys: %s.",
                $name,
                implode(', ', $unknownKeys),
                implode(', ', self::KNOWN_PROFILE_KEYS),
            ));
        }

        // Distinguish "mode key absent" (default to serial) from
        // "mode key explicitly null" (env-var-backed config with the
        // env unset — `'mode' => env('FOO')` returns null which is a
        // misconfig, not an opt-in to serial). The `??` shortcut
        // would silently swallow both cases and disable lazy-parallel
        // behavior in production without surfacing the env mistake.
        if (array_key_exists('mode', $definition) && $definition['mode'] === null) {
            // The fallback when `mode` is absent depends on whether the
            // profile inherits a built-in: an override of `ci` /
            // `nightly` keeps the built-in's mode, an override of
            // `smoke` or a brand-new profile defaults to serial. The
            // diagnostic spells out both paths so operators wiring
            // env-backed overrides know what to expect.
            throw new EvalRunException(sprintf(
                "Batch profile '%s' mode is null. Set 'mode' to '%s' or '%s', or omit the key (overrides of built-in profiles inherit the built-in mode; brand-new profiles default to '%s').",
                $name,
                BatchOptions::MODE_SERIAL,
                BatchOptions::MODE_LAZY_PARALLEL,
                BatchOptions::MODE_SERIAL,
            ));
        }
        $mode = $definition['mode'] ?? BatchOptions::MODE_SERIAL;
        if (! is_string($mode)) {
            throw new EvalRunException(sprintf("Batch profile '%s' mode must be a string.", $name));
        }

        return new BatchProfile(
            name: $name,
            mode: $mode,
            concurrency: $this->normalizePositiveInt($name, 'concurrency', $definition['concurrency'] ?? null),
            queue: $this->normalizeQueue($name, $definition['queue'] ?? null),
            timeoutSeconds: $this->normalizePositiveInt($name, 'timeout_seconds', $definition['timeout_seconds'] ?? null),
            waitTimeoutSeconds: $this->normalizePositiveInt($name, 'wait_timeout_seconds', $definition['wait_timeout_seconds'] ?? null),
            resultTtlSeconds: $this->normalizePositiveInt($name, 'result_ttl_seconds', $definition['result_ttl_seconds'] ?? null),
            chunkSize: $this->normalizePositiveInt($name, 'chunk_size', $definition['chunk_size'] ?? null),
            rateLimit: $this->normalizePositiveInt($name, 'rate_limit', $definition['rate_limit'] ?? null),
            rateWindowSeconds: $this->normalizePositiveInt($name, 'rate_window_seconds', $definition['rate_window_seconds'] ?? null),
            checkpointEvery: $this->normalizePositiveInt($name, 'checkpoint_every', $definition['checkpoint_every'] ?? null),
        );
    }

    private function normalizePositiveInt(string $profileName, string $field, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            if ($value < 1) {
                throw new EvalRunException(sprintf("Batch profile '%s' %s must be a positive integer.", $profileName, $field));
            }

            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;
            if ($int < 1) {
                throw new EvalRunException(sprintf("Batch profile '%s' %s must be a positive integer.", $profileName, $field));
            }

            return $int;
        }

        throw new EvalRunException(sprintf("Batch profile '%s' %s must be a positive integer or null.", $profileName, $field));
    }

    private function normalizeQueue(string $profileName, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new EvalRunException(sprintf("Batch profile '%s' queue must be null or a non-empty string.", $profileName));
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            throw new EvalRunException(sprintf("Batch profile '%s' queue must be null or a non-empty string.", $profileName));
        }

        return $trimmed;
    }
}
