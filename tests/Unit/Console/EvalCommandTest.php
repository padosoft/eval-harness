<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Tests\Fixtures\TestRegistrar;
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
            $this->assertSame('cli.smoke', $decoded['dataset']);
        } finally {
            @unlink($tmp);
        }
    }
}
