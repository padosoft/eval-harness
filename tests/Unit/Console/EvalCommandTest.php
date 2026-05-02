<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console;

use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Tests\Fixtures\InvalidUtf8Registrar;
use Padosoft\EvalHarness\Tests\Fixtures\TestRegistrar;
use Padosoft\EvalHarness\Tests\Fixtures\TestSampleRunner;
use Padosoft\EvalHarness\Tests\TestCase;

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
