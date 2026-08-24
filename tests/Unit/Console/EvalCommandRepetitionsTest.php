<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Tests\TestCase;

final class EvalCommandRepetitionsTest extends TestCase
{
    public function test_repetitions_option_repeats_the_system_under_test(): void
    {
        // A counter object rather than a by-reference int: the binding is an
        // arrow function, and those capture by value.
        $counter = new class
        {
            public int $calls = 0;
        };

        $this->registerDataset('cli.repetitions');
        $this->app->bind('eval-harness.sut', fn () => function () use ($counter): string {
            $counter->calls++;

            return 'hi';
        });

        $this->artisan('eval-harness:run', [
            'dataset' => 'cli.repetitions',
            '--repetitions' => '3',
        ])->assertExitCode(0);

        $this->assertSame(3, $counter->calls);
    }

    public function test_json_report_reports_the_repetitions(): void
    {
        $this->registerDataset('cli.repetitions.json');
        $this->app->bind('eval-harness.sut', fn () => fn (): string => 'hi');

        $path = tempnam(sys_get_temp_dir(), 'eval-harness-report').'.json';

        try {
            $this->artisan('eval-harness:run', [
                'dataset' => 'cli.repetitions.json',
                '--repetitions' => '2',
                '--json' => true,
                '--out' => $path,
                '--raw-path' => true,
            ])->assertExitCode(0);

            /** @var array<string, mixed> $json */
            $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(2, $json['repetitions']);
            $this->assertSame(1, $json['total_rows']);
            $this->assertSame(2, $json['total_samples']);
            $this->assertEqualsWithDelta(1.0, $json['pass_rate'], 1e-9);
            $this->assertArrayHasKey('precision', $json);
            $this->assertCount(1, $json['sample_aggregates']);
        } finally {
            @unlink($path);
        }
    }

    public function test_non_numeric_repetitions_are_rejected(): void
    {
        $this->registerDataset('cli.repetitions.invalid');

        $this->artisan('eval-harness:run', [
            'dataset' => 'cli.repetitions.invalid',
            '--repetitions' => 'many',
        ])
            ->expectsOutputToContain('--repetitions option requires a positive integer')
            ->assertExitCode(1);
    }

    public function test_zero_repetitions_are_rejected(): void
    {
        $this->registerDataset('cli.repetitions.zero');

        $this->artisan('eval-harness:run', [
            'dataset' => 'cli.repetitions.zero',
            '--repetitions' => '0',
        ])
            ->expectsOutputToContain('--repetitions option requires a positive integer')
            ->assertExitCode(1);
    }

    private function registerDataset(string $name): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset($name)
            ->withSamples([new DatasetSample(id: 's1', input: [], expectedOutput: 'hi')])
            ->withMetrics(['exact-match'])
            ->register();
    }
}
