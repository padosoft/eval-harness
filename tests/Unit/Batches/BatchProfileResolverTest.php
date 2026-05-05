<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Batches;

use Illuminate\Config\Repository;
use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Batches\BatchProfile;
use Padosoft\EvalHarness\Batches\BatchProfileResolver;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use PHPUnit\Framework\TestCase;

final class BatchProfileResolverTest extends TestCase
{
    public function test_built_in_profiles_are_available_without_config(): void
    {
        $resolver = new BatchProfileResolver;

        $this->assertSame(
            [BatchProfile::NAME_CI, BatchProfile::NAME_SMOKE, BatchProfile::NAME_NIGHTLY],
            $resolver->names(),
        );
    }

    public function test_ci_profile_targets_lazy_parallel_with_sane_throughput(): void
    {
        $resolver = new BatchProfileResolver;

        $profile = $resolver->resolve(BatchProfile::NAME_CI);

        $this->assertSame(BatchProfile::NAME_CI, $profile->name);
        $this->assertSame(BatchOptions::MODE_LAZY_PARALLEL, $profile->mode);
        $this->assertSame(4, $profile->concurrency);
        $this->assertSame(30, $profile->timeoutSeconds);
        $this->assertSame(120, $profile->waitTimeoutSeconds);
        $this->assertSame(4, $profile->chunkSize);
        $this->assertNull($profile->rateLimit);
        $this->assertSame(25, $profile->checkpointEvery);
    }

    public function test_smoke_profile_stays_serial(): void
    {
        $resolver = new BatchProfileResolver;

        $profile = $resolver->resolve(BatchProfile::NAME_SMOKE);

        $this->assertSame(BatchOptions::MODE_SERIAL, $profile->mode);
        $this->assertNull($profile->concurrency);
        $this->assertNull($profile->queue);
        $this->assertNull($profile->chunkSize);
        $this->assertNull($profile->rateLimit);
        $this->assertNull($profile->checkpointEvery);
    }

    public function test_nightly_profile_includes_rate_limit_defaults(): void
    {
        $resolver = new BatchProfileResolver;

        $profile = $resolver->resolve(BatchProfile::NAME_NIGHTLY);

        $this->assertSame(BatchOptions::MODE_LAZY_PARALLEL, $profile->mode);
        $this->assertSame(16, $profile->concurrency);
        $this->assertSame(120, $profile->timeoutSeconds);
        $this->assertSame(600, $profile->waitTimeoutSeconds);
        $this->assertSame(16, $profile->chunkSize);
        $this->assertSame(60, $profile->rateLimit);
        $this->assertSame(60, $profile->rateWindowSeconds);
        $this->assertSame(100, $profile->checkpointEvery);
    }

    public function test_unknown_profile_lists_available_profiles(): void
    {
        $resolver = new BatchProfileResolver;

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Unknown batch profile 'release'. Available profiles: ci, smoke, nightly.");

        $resolver->resolve('release');
    }

    public function test_config_overrides_specific_profile_fields(): void
    {
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        'ci' => ['concurrency' => 8, 'rate_limit' => 30],
                    ],
                ],
            ],
        ]);

        $profile = (new BatchProfileResolver($config))->resolve(BatchProfile::NAME_CI);

        $this->assertSame(8, $profile->concurrency);
        $this->assertSame(30, $profile->rateLimit);
        $this->assertSame(BatchOptions::MODE_LAZY_PARALLEL, $profile->mode);
        $this->assertSame(120, $profile->waitTimeoutSeconds);
    }

    public function test_config_can_register_custom_profiles(): void
    {
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        'release' => [
                            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
                            'concurrency' => 24,
                            'queue' => 'evals-release',
                            'timeout_seconds' => 90,
                            'wait_timeout_seconds' => 600,
                            'chunk_size' => 24,
                            'rate_limit' => 90,
                            'rate_window_seconds' => 60,
                            'checkpoint_every' => 50,
                        ],
                    ],
                ],
            ],
        ]);

        $resolver = new BatchProfileResolver($config);

        $this->assertContains('release', $resolver->names());
        $profile = $resolver->resolve('release');
        $this->assertSame(24, $profile->concurrency);
        $this->assertSame('evals-release', $profile->queue);
        $this->assertSame(90, $profile->rateLimit);
    }

    public function test_invalid_profile_field_value_throws(): void
    {
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        'ci' => ['concurrency' => 'four'],
                    ],
                ],
            ],
        ]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Batch profile 'ci' concurrency must be a positive integer or null.");

        new BatchProfileResolver($config);
    }

    public function test_blank_profile_name_in_config_is_rejected(): void
    {
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        ' ' => ['mode' => BatchOptions::MODE_SERIAL],
                    ],
                ],
            ],
        ]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Batch profile names must be non-empty strings');

        new BatchProfileResolver($config);
    }

    public function test_override_can_flip_built_in_lazy_profile_to_serial(): void
    {
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        // ci is lazy-parallel by default; flipping it to
                        // serial must work even with a single-field
                        // override (host apps must not be required to
                        // null every inherited lazy-only field).
                        'ci' => ['mode' => BatchOptions::MODE_SERIAL],
                    ],
                ],
            ],
        ]);

        $profile = (new BatchProfileResolver($config))->resolve(BatchProfile::NAME_CI);

        $this->assertSame(BatchOptions::MODE_SERIAL, $profile->mode);
        $this->assertNull($profile->concurrency);
        $this->assertNull($profile->timeoutSeconds);
        $this->assertNull($profile->waitTimeoutSeconds);
        $this->assertNull($profile->chunkSize);
        $this->assertNull($profile->checkpointEvery);
    }

    public function test_override_keeps_explicit_lazy_only_field_then_fails_validation_under_serial_mode(): void
    {
        // The resolver should NOT silently drop an explicit lazy-only
        // field the operator set. Combined with mode=serial it is a real
        // misconfiguration and BatchProfile must surface it.
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        'ci' => ['mode' => BatchOptions::MODE_SERIAL, 'concurrency' => 4],
                    ],
                ],
            ],
        ]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Batch profile 'ci' uses serial mode and cannot set concurrency above 1.");

        new BatchProfileResolver($config);
    }

    public function test_serial_profile_rejects_lazy_parallel_only_field(): void
    {
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        'smoke' => ['rate_limit' => 5],
                    ],
                ],
            ],
        ]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Batch profile 'smoke' uses serial mode and cannot set rate_limit.");

        new BatchProfileResolver($config);
    }

    public function test_profile_rejects_rate_window_seconds_without_rate_limit(): void
    {
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        'broken' => [
                            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
                            'rate_window_seconds' => 30,
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Batch profile 'broken' rate_window_seconds is only meaningful with rate_limit");

        new BatchProfileResolver($config);
    }

    public function test_profile_rejects_chunk_size_greater_than_concurrency(): void
    {
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        'unbalanced' => [
                            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
                            'concurrency' => 4,
                            'chunk_size' => 8,
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Batch profile 'unbalanced' chunk_size (8) cannot exceed concurrency (4).");

        new BatchProfileResolver($config);
    }

    public function test_resolver_rejects_non_array_profiles_config(): void
    {
        // A misdeclared `profiles` key would otherwise be silently
        // ignored, and host-app overrides would never apply in
        // production while looking accepted in source.
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => 'not-an-array',
                ],
            ],
        ]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('eval-harness.batches.profiles must be a map of profile-name => override-array, got string.');

        new BatchProfileResolver($config);
    }

    public function test_profile_rejects_chunk_size_without_concurrency(): void
    {
        // chunk_size with no explicit concurrency would silently clamp
        // to the baseline (1) in the trait, which would degrade
        // throughput without any operator-visible signal.
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        'partial' => [
                            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
                            'chunk_size' => 8,
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Batch profile 'partial' sets chunk_size but does not set concurrency");

        new BatchProfileResolver($config);
    }

    public function test_resolver_rejects_unknown_profile_keys(): void
    {
        // Typos like `concurency` or `checkpointEvery` would otherwise
        // be silently ignored, leaving the built-in default in place
        // while the operator believed their override applied.
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        'typo' => [
                            'mode' => BatchOptions::MODE_LAZY_PARALLEL,
                            'concurrency' => 4,
                            'concurency' => 8,
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Batch profile 'typo' has unknown key(s): concurency");

        new BatchProfileResolver($config);
    }

    public function test_resolver_rejects_explicit_null_mode(): void
    {
        // Round-26 fix: explicit null `mode` (e.g. `'mode' =>
        // env('FOO')` when the env var is unset) must NOT silently
        // fall back to serial. Operators wiring profiles through env
        // would otherwise see lazy-parallel behavior disabled in
        // production with no diagnostic. A missing key (no `mode`
        // entry at all) still defaults to serial — only an explicit
        // null is rejected.
        $config = new Repository([
            'eval-harness' => [
                'batches' => [
                    'profiles' => [
                        'env-backed' => [
                            'mode' => null,
                            'concurrency' => 4,
                        ],
                    ],
                ],
            ],
        ]);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Batch profile 'env-backed' mode is null.");

        new BatchProfileResolver($config);
    }

    public function test_resolve_rejects_blank_name(): void
    {
        $resolver = new BatchProfileResolver;

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Batch profile name must be a non-empty string');

        $resolver->resolve('');
    }
}
