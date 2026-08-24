<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Metrics\MetricScore;
use Padosoft\EvalHarness\Tests\TestCase;

final class EvalCommandBudgetTest extends TestCase
{
    /**
     * A halted run is incomplete data. Exiting zero would let a gate read
     * "we ran out of money before we got to the bad rows" as a pass.
     */
    public function test_a_halted_run_exits_non_zero_even_with_no_failures(): void
    {
        $this->registerDataset('cli.budget.halt', rows: 5, dollarsPerRow: 0.10);
        $this->app->bind('eval-harness.sut', fn () => fn (): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'cli.budget.halt',
            '--budget-usd' => '0.15',
        ])
            ->expectsOutputToContain('Halted on budget')
            ->assertExitCode(1);
    }

    public function test_a_run_inside_its_budget_still_passes(): void
    {
        $this->registerDataset('cli.budget.inside', rows: 2, dollarsPerRow: 0.01);
        $this->app->bind('eval-harness.sut', fn () => fn (): string => 'hi');

        $this->artisan('eval-harness:run', [
            'dataset' => 'cli.budget.inside',
            '--budget-usd' => '10',
        ])->assertExitCode(0);
    }

    /**
     * Rejected before the run rather than after: discovering a typo in a flag
     * once the tokens are spent has already cost the thing the flag existed
     * to protect.
     */
    public function test_a_bad_budget_is_rejected_before_the_run_starts(): void
    {
        $counter = new class
        {
            public int $calls = 0;
        };

        $this->registerDataset('cli.budget.bad', rows: 2, dollarsPerRow: 0.01);
        $this->app->bind('eval-harness.sut', fn () => function () use ($counter): string {
            $counter->calls++;

            return 'hi';
        });

        $this->artisan('eval-harness:run', [
            'dataset' => 'cli.budget.bad',
            '--budget-usd' => 'plenty',
        ])
            ->expectsOutputToContain('--budget-usd option requires a positive amount')
            ->assertExitCode(1);

        $this->assertSame(0, $counter->calls, 'no tokens were spent discovering the typo');
    }

    public function test_a_zero_budget_is_rejected(): void
    {
        $this->registerDataset('cli.budget.zero', rows: 1, dollarsPerRow: 0.01);

        $this->artisan('eval-harness:run', [
            'dataset' => 'cli.budget.zero',
            '--budget-usd' => '0',
        ])->assertExitCode(1);
    }

    public function test_the_written_json_report_records_the_halt(): void
    {
        $this->registerDataset('cli.budget.json', rows: 6, dollarsPerRow: 0.10);
        $this->app->bind('eval-harness.sut', fn () => fn (): string => 'hi');

        $path = tempnam(sys_get_temp_dir(), 'eval-harness-budget').'.json';

        try {
            $this->artisan('eval-harness:run', [
                'dataset' => 'cli.budget.json',
                '--budget-usd' => '0.25',
                '--json' => true,
                '--out' => $path,
                '--raw-path' => true,
            ])->assertExitCode(1);

            /** @var array<string, mixed> $json */
            $json = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            $this->assertTrue($json['budget']['halted']);
            $this->assertSame(3, $json['budget']['completed_rows']);
            $this->assertEqualsWithDelta(0.25, (float) $json['budget']['limit_usd'], 1e-9);
            $this->assertEqualsWithDelta(0.30, (float) $json['cost']['total_usd'], 1e-9);
            $this->assertSame(3, $json['total_samples']);
        } finally {
            @unlink($path);
        }
    }

    private function registerDataset(string $name, int $rows, float $dollarsPerRow): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $samples = [];
        foreach (range(1, $rows) as $index) {
            $samples[] = new DatasetSample(id: 's'.$index, input: [], expectedOutput: 'hi');
        }

        $engine->dataset($name)
            ->withSamples($samples)
            ->withMetrics([new BilledMetric($dollarsPerRow)])
            ->register();
    }
}

final class BilledMetric implements Metric
{
    public function __construct(private readonly float $costUsd) {}

    public function name(): string
    {
        return 'billed';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        return new MetricScore(1.0, [
            'usage' => ['model' => 'billed-model', 'prompt_tokens' => 4, 'completion_tokens' => 1, 'cost_usd' => $this->costUsd],
        ]);
    }
}
