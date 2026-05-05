<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use Padosoft\EvalHarness\Console\EvalCommand;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Tests\Fixtures\InvalidUtf8Registrar;
use Padosoft\EvalHarness\Tests\Fixtures\SavedOutputsOnlyRegistrar;
use Padosoft\EvalHarness\Tests\Fixtures\TestRegistrar;
use Padosoft\EvalHarness\Tests\Fixtures\TestSampleRunner;
use Padosoft\EvalHarness\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

final class EvalCommandTest extends TestCase
{
    public function test_unknown_dataset_without_registrar_fails(): void
    {
        $this->artisan('eval-harness:run', ['dataset' => 'no.such.dataset'])
            ->expectsOutputToContain("Dataset 'no.such.dataset' is not registered.")
            ->assertExitCode(1);
    }

    public function test_registrar_registers_dataset_and_runs(): void
    {
        $this->artisan('eval-harness:run', [
            'dataset' => 'cli.smoke',
            '--registrar' => TestRegistrar::class,
        ])->assertExitCode(0);
    }

    public function test_runs_with_pre_registered_dataset_and_bound_sut(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('preregistered')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', ['dataset' => 'preregistered'])
            ->assertExitCode(0);
    }

    public function test_batch_serial_option_runs_with_bound_sut(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('preregistered-batch-serial')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'preregistered-batch-serial',
            '--batch' => 'serial',
        ])->assertExitCode(0);
    }

    public function test_empty_batch_options_keep_omitted_defaults(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('empty-batch-option-defaults')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'empty-batch-option-defaults',
            '--concurrency' => '',
            '--timeout' => '',
            '--batch-timeout' => '',
        ])->assertExitCode(0);
    }

    public function test_invalid_batch_mode_returns_failure(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('invalid-batch-mode')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'invalid-batch-mode',
            '--batch' => 'parallel',
        ])
            ->expectsOutputToContain("Unsupported batch mode 'parallel'")
            ->assertExitCode(1);
    }

    public function test_batch_lazy_parallel_option_runs_with_bound_sample_runner_class(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('preregistered-batch-lazy-parallel')
            ->withSamples([
                new DatasetSample(id: 's1', input: [], expectedOutput: 'hi'),
                new DatasetSample(id: 's2', input: [], expectedOutput: 'hi'),
            ])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', TestSampleRunner::class);

        $this->artisan('eval-harness:run', [
            'dataset' => 'preregistered-batch-lazy-parallel',
            '--batch' => 'lazy-parallel',
            '--concurrency' => '2',
            '--queue' => 'evals',
            '--timeout' => '5',
            '--batch-timeout' => '30',
        ])->assertExitCode(0);
    }

    public function test_batch_lazy_parallel_rejects_callable_sut_binding(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('lazy-parallel-callable-binding')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'lazy-parallel-callable-binding',
            '--batch' => 'lazy-parallel',
        ])
            ->expectsOutputToContain('Lazy parallel batch mode requires a SampleRunner system-under-test')
            ->assertExitCode(1);
    }

    public function test_serial_batch_rejects_concurrency_greater_than_one(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('invalid-batch-concurrency')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'invalid-batch-concurrency',
            '--concurrency' => '2',
        ])
            ->expectsOutputToContain('Serial batch mode requires concurrency 1')
            ->assertExitCode(1);
    }

    public function test_serial_batch_rejects_timeout(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('invalid-batch-timeout')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'invalid-batch-timeout',
            '--timeout' => '30',
        ])
            ->expectsOutputToContain('Serial batch mode does not use a timeout')
            ->assertExitCode(1);
    }

    public function test_serial_batch_rejects_batch_timeout(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('invalid-batch-wait-timeout')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'invalid-batch-wait-timeout',
            '--batch-timeout' => '30',
        ])
            ->expectsOutputToContain('Serial batch mode does not use a wait timeout')
            ->assertExitCode(1);
    }

    public function test_outputs_warning_routes_to_stderr_on_console_output_interface(): void
    {
        // Round-32 fix: when the active output supports STDERR
        // (real CLI via ConsoleOutputInterface), the warning must
        // go to STDERR — not to the regular line writer — so
        // `eval-harness:run --outputs ... --json` can pipe stdout
        // to a JSON parser without contamination. This test
        // bypasses the artisan invocation harness and drives
        // EvalCommand directly with a ConsoleOutput so STDERR/STDOUT
        // are distinguishable.
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('saved-output-stderr-route')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $outputs = tempnam(sys_get_temp_dir(), 'eval-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);

        try {
            file_put_contents($outputs, json_encode(['outputs' => ['s1' => 'hi']], JSON_THROW_ON_ERROR));

            /** @var EvalCommand $command */
            $command = $this->app->make(EvalCommand::class);
            $command->setLaravel($this->app);

            $stdout = new BufferedOutput;
            $stderr = new BufferedOutput;
            $output = new class($stdout, $stderr) extends Output implements ConsoleOutputInterface
            {
                public function __construct(
                    private BufferedOutput $stdout,
                    private BufferedOutput $stderr,
                ) {
                    parent::__construct();
                }

                protected function doWrite(string $message, bool $newline): void
                {
                    $this->stdout->write($message, $newline);
                }

                public function getErrorOutput(): OutputInterface
                {
                    return $this->stderr;
                }

                public function setErrorOutput(OutputInterface $error): void
                {
                    //
                }

                public function section(): ConsoleSectionOutput
                {
                    throw new \LogicException('not used');
                }
            };

            $input = new ArrayInput([
                'dataset' => 'saved-output-stderr-route',
                '--outputs' => $outputs,
                '--batch-profile' => 'ci',
                '--json' => true,
                '--out' => $report,
            ], $command->getDefinition());

            $command->run($input, $output);

            $stdoutText = $stdout->fetch();
            $stderrText = $stderr->fetch();

            // Warning must be on STDERR only, leaving stdout
            // available for any payload writers without
            // contamination.
            $this->assertStringContainsString('Ignoring batch flags', $stderrText);
            $this->assertStringContainsString('--batch-profile', $stderrText);
            $this->assertStringNotContainsString('Ignoring batch flags', $stdoutText);
        } finally {
            @unlink($outputs);
            @unlink($report);
        }
    }

    public function test_empty_batch_profile_is_rejected(): void
    {
        // Round-36 fix: `--batch-profile=` (empty) was silently
        // treated as "no profile". An unset CI variable
        // (`--batch-profile=$EVAL_PROFILE` with `EVAL_PROFILE`
        // unset) would change batch mode and backpressure with no
        // diagnostic. Only the numeric flags document empty-value
        // fall-through to profile/baseline default — the profile
        // name itself does not. Operators that do not want a
        // profile must omit the flag entirely.
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('reject-empty-profile')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'reject-empty-profile',
            '--batch-profile' => '',
        ])
            ->expectsOutputToContain('--batch-profile option requires a non-empty profile name')
            ->assertExitCode(1);
    }

    public function test_outputs_warns_when_batch_flags_are_passed(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('saved-output-warn-cli')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $outputs = tempnam(sys_get_temp_dir(), 'eval-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);

        try {
            file_put_contents($outputs, json_encode(['outputs' => ['s1' => 'hi']], JSON_THROW_ON_ERROR));

            // --outputs bypasses the batch dispatch path, so passing
            // --batch-profile / --rate-limit alongside --outputs is
            // a misuse: the trait validation never runs and operators
            // get no signal that the values are dropped. The runtime
            // warning is the only safety net catching typos like
            // `--rate-limit=abc`. Use Artisan::call so we can read the
            // captured output directly (the PendingCommand wrapper
            // routes assertions through a different output path that
            // does not see <comment>-styled `line()` writes).
            $exit = Artisan::call('eval-harness:run', [
                'dataset' => 'saved-output-warn-cli',
                '--outputs' => $outputs,
                '--batch-profile' => 'ci',
                '--rate-limit' => '5',
                '--json' => true,
                '--out' => $report,
            ]);
            $output = Artisan::output();

            $this->assertSame(0, $exit, 'Saved-output run with extra batch flags must still exit 0; got output: '.$output);
            $this->assertStringContainsString('Ignoring batch flags', $output);
            $this->assertStringContainsString('--batch-profile', $output);
            $this->assertStringContainsString('--rate-limit', $output);
            $this->assertStringContainsString('--outputs is set', $output);
        } finally {
            @unlink($outputs);
            @unlink($report);
        }
    }

    public function test_outputs_warning_suppressed_when_json_without_out_in_buffered_output(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('saved-output-warn-json-no-out')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $outputs = tempnam(sys_get_temp_dir(), 'eval-outputs-');
        $this->assertNotFalse($outputs);

        try {
            file_put_contents($outputs, json_encode(['outputs' => ['s1' => 'hi']], JSON_THROW_ON_ERROR));

            // Round-33 fix: when --json is set and --out is NOT set,
            // stdout is the JSON payload programmatic callers parse
            // via Artisan::output(). Writing the warning into the
            // single-stream BufferedOutput buffer would break that
            // machine-parseable contract. The warning must be
            // suppressed (CLI users still see it on STDERR via the
            // ConsoleOutputInterface branch).
            $exit = Artisan::call('eval-harness:run', [
                'dataset' => 'saved-output-warn-json-no-out',
                '--outputs' => $outputs,
                '--batch-profile' => 'ci',
                '--rate-limit' => '5',
                '--json' => true,
            ]);
            $output = Artisan::output();

            $this->assertSame(0, $exit, 'Saved-output JSON run with extra batch flags must still exit 0; got output: '.$output);
            $this->assertStringNotContainsString('Ignoring batch flags', $output);
            // The captured buffer must remain valid JSON for
            // programmatic consumption — decode round-trip proves
            // no diagnostic line leaked into the payload.
            $decoded = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertSame('hi', $decoded['samples'][0]['actual_output']);
        } finally {
            @unlink($outputs);
        }
    }

    public function test_outputs_warning_detects_explicit_default_valued_flag_via_sentinel(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('saved-output-warn-default-valued')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $outputs = tempnam(sys_get_temp_dir(), 'eval-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);

        try {
            file_put_contents($outputs, json_encode(['outputs' => ['s1' => 'hi']], JSON_THROW_ON_ERROR));

            // Round-32 sentinel-based fallback: explicit
            // default-valued flag `--batch=serial` (matches the
            // signature default 'serial') passed via Artisan::call
            // must still fire the warning. hasParameterOption +
            // value-vs-default comparison alone misses this case
            // because the resolved value matches the default; only
            // the sentinel `getParameterOption` round-trip catches
            // it. A regression here would silently let explicitly-
            // passed default values bypass the runtime warning.
            $exit = Artisan::call('eval-harness:run', [
                'dataset' => 'saved-output-warn-default-valued',
                '--outputs' => $outputs,
                '--batch' => 'serial',
                '--json' => true,
                '--out' => $report,
            ]);
            $output = Artisan::output();

            $this->assertSame(0, $exit, 'Saved-output run with explicit default-valued batch flag must still exit 0; got: '.$output);
            $this->assertStringContainsString('Ignoring batch flags', $output);
            $this->assertStringContainsString('--batch', $output);
        } finally {
            @unlink($outputs);
            @unlink($report);
        }
    }

    public function test_outputs_does_not_warn_when_no_batch_flags_passed(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('saved-output-quiet-cli')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $outputs = tempnam(sys_get_temp_dir(), 'eval-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);

        try {
            file_put_contents($outputs, json_encode(['outputs' => ['s1' => 'hi']], JSON_THROW_ON_ERROR));

            // No batch flags passed → no warning. Documents the
            // false-positive guard: the warning only fires for flags
            // the operator actually passed, not for defaulted values.
            $exit = Artisan::call('eval-harness:run', [
                'dataset' => 'saved-output-quiet-cli',
                '--outputs' => $outputs,
                '--json' => true,
                '--out' => $report,
            ]);
            $output = Artisan::output();

            $this->assertSame(0, $exit);
            $this->assertStringNotContainsString('Ignoring batch flags', $output);
        } finally {
            @unlink($outputs);
            @unlink($report);
        }
    }

    public function test_outputs_option_runs_without_bound_sut(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('saved-output-cli')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $outputs = tempnam(sys_get_temp_dir(), 'eval-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);

        try {
            file_put_contents($outputs, json_encode(['outputs' => ['s1' => 'hi']], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:run', [
                'dataset' => 'saved-output-cli',
                '--outputs' => $outputs,
                '--json' => true,
                '--out' => $report,
            ])->assertExitCode(0);

            $decoded = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('hi', $decoded['samples'][0]['actual_output']);
            $this->assertEqualsWithDelta(1.0, $decoded['metrics']['exact-match']['mean'], 1e-9);
        } finally {
            @unlink($outputs);
            @unlink($report);
        }
    }

    public function test_outputs_option_runs_after_registrar_registers_dataset_without_bound_sut(): void
    {
        $outputs = tempnam(sys_get_temp_dir(), 'eval-outputs-');
        $report = tempnam(sys_get_temp_dir(), 'eval-report-');
        $this->assertNotFalse($outputs);
        $this->assertNotFalse($report);

        try {
            file_put_contents($outputs, json_encode([
                'outputs' => [
                    's1' => 'hello',
                    's2' => 'world',
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:run', [
                'dataset' => 'cli.saved-output-registrar',
                '--registrar' => SavedOutputsOnlyRegistrar::class,
                '--outputs' => $outputs,
                '--json' => true,
                '--out' => $report,
            ])->assertExitCode(0);

            $decoded = json_decode((string) file_get_contents($report), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('cli.saved-output-registrar', $decoded['dataset']);
            $this->assertSame('hello', $decoded['samples'][0]['actual_output']);
            $this->assertSame('world', $decoded['samples'][1]['actual_output']);
            $this->assertEqualsWithDelta(1.0, $decoded['metrics']['exact-match']['mean'], 1e-9);
        } finally {
            @unlink($outputs);
            @unlink($report);
        }
    }

    public function test_outputs_option_surfaces_missing_sample_errors(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('saved-output-cli-missing')
            ->withSamples([
                new DatasetSample(id: 's1', input: [], expectedOutput: 'hi'),
                new DatasetSample(id: 's2', input: [], expectedOutput: 'bye'),
            ])
            ->withMetrics(['exact-match'])
            ->register();

        $outputs = tempnam(sys_get_temp_dir(), 'eval-outputs-');
        $this->assertNotFalse($outputs);

        try {
            file_put_contents($outputs, json_encode(['outputs' => ['s1' => 'hi']], JSON_THROW_ON_ERROR));

            $this->artisan('eval-harness:run', [
                'dataset' => 'saved-output-cli-missing',
                '--outputs' => $outputs,
            ])
                ->expectsOutputToContain('missing sample ids: s2')
                ->assertExitCode(1);
        } finally {
            @unlink($outputs);
        }
    }

    public function test_outputs_option_surfaces_invalid_output_file_errors(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('saved-output-cli-invalid-file')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $outputs = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eval-outputs-'.uniqid('', true).'.json';

        try {
            file_put_contents($outputs, '{not-json');

            $this->artisan('eval-harness:run', [
                'dataset' => 'saved-output-cli-invalid-file',
                '--outputs' => $outputs,
            ])
                ->expectsOutputToContain('contains invalid JSON')
                ->assertExitCode(1);
        } finally {
            @unlink($outputs);
        }
    }

    public function test_outputs_option_requires_non_empty_path(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('saved-output-cli-empty-path')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $this->artisan('eval-harness:run', [
            'dataset' => 'saved-output-cli-empty-path',
            '--outputs' => '',
        ])
            ->expectsOutputToContain('The --outputs option requires a non-empty file path.')
            ->assertExitCode(1);
    }

    public function test_runs_with_pre_registered_dataset_and_bound_sample_runner(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('runner-preregistered')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $this->app->bind('eval-harness.sut', fn (): SampleRunner => new class implements SampleRunner
        {
            public function run(SampleInvocation $sample): string
            {
                return 'hi';
            }
        });

        $this->artisan('eval-harness:run', ['dataset' => 'runner-preregistered'])
            ->assertExitCode(0);
    }

    public function test_runs_with_pre_registered_dataset_and_bound_sample_runner_class(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('runner-class-preregistered')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $this->app->bind('eval-harness.sut', TestSampleRunner::class);

        $this->artisan('eval-harness:run', ['dataset' => 'runner-class-preregistered'])
            ->assertExitCode(0);
    }

    public function test_bound_sut_must_be_callable_or_sample_runner(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('invalid-sut-binding')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $this->app->instance('eval-harness.sut', new \stdClass);

        $this->artisan('eval-harness:run', ['dataset' => 'invalid-sut-binding'])
            ->expectsOutputToContain("System-under-test bound under 'eval-harness.sut' must resolve to a callable or SampleRunner; got stdClass.")
            ->assertExitCode(1);
    }

    public function test_missing_bound_sut_uses_missing_binding_message(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('missing-sut-binding')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();

        $this->artisan('eval-harness:run', ['dataset' => 'missing-sut-binding'])
            ->expectsOutputToContain("No system-under-test bound under 'eval-harness.sut'.")
            ->assertExitCode(1);
    }

    public function test_writes_to_out_path(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'eval-out-');
        $this->assertNotFalse($tmp);

        try {
            $this->artisan('eval-harness:run', [
                'dataset' => 'cli.smoke',
                '--registrar' => TestRegistrar::class,
                '--out' => $tmp,
            ])->assertExitCode(0);

            $contents = (string) file_get_contents($tmp);
            $this->assertStringContainsString('# Eval report — cli.smoke', $contents);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_json_flag_emits_json_to_out(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'eval-out-');
        $this->assertNotFalse($tmp);

        try {
            $this->artisan('eval-harness:run', [
                'dataset' => 'cli.smoke',
                '--registrar' => TestRegistrar::class,
                '--json' => true,
                '--out' => $tmp,
            ])->assertExitCode(0);

            $contents = (string) file_get_contents($tmp);
            $this->assertJson($contents);
            $decoded = json_decode($contents, true);
            $this->assertSame('eval-harness.report.v1', $decoded['schema_version']);
            $this->assertSame('eval-harness.dataset.v1', $decoded['dataset_schema_version']);
            $this->assertSame('cli.smoke', $decoded['dataset']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_nonexistent_registrar_class_returns_failure_with_error(): void
    {
        $this->artisan('eval-harness:run', [
            'dataset' => 'any.dataset',
            '--registrar' => 'App\\NonExistent\\Registrar',
        ])
            ->expectsOutputToContain('does not exist')
            ->assertExitCode(1);
    }

    public function test_ci_profile_resolves_to_lazy_parallel_without_explicit_batch_flag(): void
    {
        // The ci profile mode is lazy-parallel; a closure SUT cannot satisfy
        // the lazy-parallel SampleRunner requirement, so this command must
        // fail with that exact error. Default batch mode is serial which
        // would have accepted the closure, so a green run here would mean
        // the profile was silently ignored.
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('profile-ci-resolves')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'profile-ci-resolves',
            '--batch-profile' => 'ci',
        ])
            ->expectsOutputToContain('Lazy parallel batch mode requires a SampleRunner system-under-test')
            ->assertExitCode(1);
    }

    public function test_unknown_profile_returns_failure(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('profile-unknown')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'profile-unknown',
            '--batch-profile' => 'release',
        ])
            ->expectsOutputToContain("Unknown batch profile 'release'")
            ->assertExitCode(1);
    }

    public function test_explicit_batch_flag_overrides_profile_mode(): void
    {
        // Paired with the no-override test above: without --batch=serial,
        // ci profile fails with the SampleRunner error; with --batch=serial
        // the override wins, mode resolves to serial, and the lazy-parallel
        // profile defaults are dropped. Together both tests pin the
        // explicit-CLI-wins-over-profile precedence in both directions.
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('profile-override')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'profile-override',
            '--batch-profile' => 'ci',
            '--batch' => 'serial',
        ])->assertExitCode(0);
    }

    public function test_invalid_checkpoint_every_returns_failure(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('invalid-checkpoint-every')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'invalid-checkpoint-every',
            '--batch' => 'lazy-parallel',
            '--checkpoint-every' => 'abc',
        ])
            ->expectsOutputToContain('The --checkpoint-every option must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_invalid_result_ttl_seconds_returns_failure(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('invalid-result-ttl')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'invalid-result-ttl',
            '--batch' => 'lazy-parallel',
            '--result-ttl-seconds' => 'abc',
        ])
            ->expectsOutputToContain('The --result-ttl-seconds option must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_invalid_chunk_size_returns_failure(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('invalid-chunk')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'invalid-chunk',
            '--batch' => 'lazy-parallel',
            '--chunk-size' => 'abc',
        ])
            ->expectsOutputToContain('The --chunk-size option must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_invalid_rate_window_seconds_returns_failure(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('invalid-rate-window')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'invalid-rate-window',
            '--batch' => 'lazy-parallel',
            '--rate-window-seconds' => 'abc',
        ])
            ->expectsOutputToContain('The --rate-window-seconds option must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_rate_window_seconds_without_rate_limit_returns_failure(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('rate-window-without-limit')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', TestSampleRunner::class);

        $this->artisan('eval-harness:run', [
            'dataset' => 'rate-window-without-limit',
            '--batch' => 'lazy-parallel',
            '--rate-window-seconds' => '30',
        ])
            ->expectsOutputToContain('Batch rate window seconds is only meaningful with a rate limit')
            ->assertExitCode(1);
    }

    public function test_rate_limit_with_window_runs_under_sync_queue(): void
    {
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('cache.default', 'array');

        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('rate-window-happy-path')
            ->withSamples([
                new DatasetSample(id: 's1', input: [], expectedOutput: 'hi'),
                new DatasetSample(id: 's2', input: [], expectedOutput: 'hi'),
            ])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', TestSampleRunner::class);

        $this->artisan('eval-harness:run', [
            'dataset' => 'rate-window-happy-path',
            '--batch' => 'lazy-parallel',
            '--queue' => 'evals',
            '--concurrency' => '2',
            '--rate-limit' => '10',
            '--rate-window-seconds' => '1',
        ])->assertExitCode(0);
    }

    public function test_invalid_rate_limit_returns_failure(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('invalid-rate-limit')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'invalid-rate-limit',
            '--batch' => 'lazy-parallel',
            '--rate-limit' => '-3',
        ])
            ->expectsOutputToContain('The --rate-limit option must be a positive integer.')
            ->assertExitCode(1);
    }

    public function test_serial_mode_rejects_explicit_rate_limit(): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);
        $engine->dataset('serial-rate-limit')
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
        $this->app->bind('eval-harness.sut', fn () => fn (array $in): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'serial-rate-limit',
            '--rate-limit' => '5',
        ])
            ->expectsOutputToContain('Serial batch mode does not use a rate limit.')
            ->assertExitCode(1);
    }

    /**
     * Regression: --json must surface json_encode failures as a
     * command-level error instead of writing an empty payload + exit 0.
     * The InvalidUtf8Registrar's SUT returns a string with a stray
     * 0xFF byte that cannot be encoded without
     * JSON_INVALID_UTF8_SUBSTITUTE.
     */
    public function test_json_encoding_failure_surfaces_as_error(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'eval-out-');
        $this->assertNotFalse($tmp);

        try {
            $this->artisan('eval-harness:run', [
                'dataset' => 'cli.invalid-utf8',
                '--registrar' => InvalidUtf8Registrar::class,
                '--json' => true,
                '--out' => $tmp,
            ])
                ->expectsOutputToContain('Failed to encode report as JSON')
                ->assertExitCode(1);

            // Output file must NOT have been created with empty
            // contents masquerading as a successful run.
            $contents = (string) file_get_contents($tmp);
            $this->assertSame('', $contents, 'Failure path must not write a payload.');
        } finally {
            @unlink($tmp);
        }
    }
}
