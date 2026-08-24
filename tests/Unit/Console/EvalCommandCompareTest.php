<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console;

use Illuminate\Support\Facades\Storage;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Regression\BaselineStore;
use Padosoft\EvalHarness\Tests\TestCase;

final class EvalCommandCompareTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.reports.disk', 'eval-compare');
        $app['config']->set('eval-harness.reports.path_prefix', 'eval-harness/reports');
    }

    /**
     * The end-to-end shape of the feature: run, promote, break a row, run
     * again with a gate, and the build stops with the row named.
     */
    public function test_a_broken_row_fails_the_gate_against_the_baseline(): void
    {
        Storage::fake('eval-compare');
        $this->registerDataset('gate.dataset');

        $this->bindSut(fn (array $input): string => $input['q'] === 'a' ? 'A' : 'B');
        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.dataset',
            '--json' => true,
            '--out' => 'green.json',
            '--promote-baseline' => true,
        ])->assertExitCode(0);

        $this->assertSame(
            'eval-harness/reports/green.json',
            $this->store()->pointer('gate.dataset')['report_path'],
        );

        $this->bindSut(fn (array $input): string => $input['q'] === 'a' ? 'WRONG' : 'B');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.dataset',
            '--json' => true,
            '--out' => 'red.json',
            '--compare' => 'baseline',
            '--comparison-out' => 'diff.json',
        ])
            ->expectsOutputToContain('Gate failed')
            ->assertExitCode(1);

        // Asserted from the artifact rather than the console: the failure line
        // is long enough for the terminal formatter to wrap it, and a test that
        // depends on where it wrapped tests the formatter, not the gate.
        $payload = $this->comparisonPayload('eval-harness/reports/diff.json');

        $this->assertSame(1, $payload['counts']['regressed']);
        $this->assertSame('s1', $payload['rows'][0]['sample_id']);
        $this->assertSame('regressed', $payload['rows'][0]['status']);
        $this->assertTrue($payload['rows'][0]['newly_failing']);
    }

    public function test_an_unchanged_run_passes_the_gate(): void
    {
        Storage::fake('eval-compare');
        $this->registerDataset('gate.stable');
        $this->bindSut(fn (array $input): string => $input['q'] === 'a' ? 'A' : 'B');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.stable',
            '--json' => true,
            '--out' => 'first.json',
            '--promote-baseline' => true,
        ])->assertExitCode(0);

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.stable',
            '--json' => true,
            '--out' => 'second.json',
            '--compare' => 'baseline',
        ])->assertExitCode(0);
    }

    public function test_max_regressions_allows_a_known_number_of_breaks(): void
    {
        Storage::fake('eval-compare');
        $this->registerDataset('gate.allowance');
        $this->bindSut(fn (array $input): string => $input['q'] === 'a' ? 'A' : 'B');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.allowance',
            '--json' => true,
            '--out' => 'first.json',
            '--promote-baseline' => true,
        ])->assertExitCode(0);

        $this->bindSut(static fn (): string => 'WRONG');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.allowance',
            '--json' => true,
            '--out' => 'second.json',
            '--compare' => 'baseline',
            '--max-regressions' => '2',
        ])->assertExitCode(0);
    }

    /**
     * Losing the baseline artifact must never turn a run that finished
     * cleanly into a failed build.
     */
    public function test_a_missing_baseline_warns_and_keeps_the_run_green(): void
    {
        Storage::fake('eval-compare');
        $this->registerDataset('gate.nobaseline');
        $this->bindSut(static fn (): string => 'A');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.nobaseline',
            '--json' => true,
            '--out' => 'run.json',
            '--compare' => 'baseline',
        ])
            ->expectsOutputToContain('No baseline to compare against')
            ->assertExitCode(0);
    }

    public function test_compare_latest_uses_the_previous_run_and_excludes_this_one(): void
    {
        Storage::fake('eval-compare');
        $this->registerDataset('gate.latest');
        $this->bindSut(fn (array $input): string => $input['q'] === 'a' ? 'A' : 'B');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.latest',
            '--json' => true,
            '--out' => 'first.json',
        ])->assertExitCode(0);

        $this->bindSut(static fn (): string => 'WRONG');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.latest',
            '--json' => true,
            '--out' => 'second.json',
            '--compare' => 'latest',
        ])
            ->expectsOutputToContain('Gate failed')
            ->assertExitCode(1);
    }

    public function test_the_comparison_payload_can_be_written_out(): void
    {
        Storage::fake('eval-compare');
        $this->registerDataset('gate.artifact');
        $this->bindSut(fn (array $input): string => $input['q'] === 'a' ? 'A' : 'B');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.artifact',
            '--json' => true,
            '--out' => 'first.json',
            '--promote-baseline' => true,
        ])->assertExitCode(0);

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.artifact',
            '--json' => true,
            '--out' => 'second.json',
            '--compare' => 'baseline',
            '--comparison-out' => 'diff.json',
        ])->assertExitCode(0);

        Storage::disk('eval-compare')->assertExists('eval-harness/reports/diff.json');

        /** @var array<string, mixed> $payload */
        $payload = json_decode(
            (string) Storage::disk('eval-compare')->get('eval-harness/reports/diff.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('eval-harness.comparison.v1', $payload['schema_version']);
        $this->assertSame(2, $payload['counts']['compared']);
        $this->assertSame(0, $payload['counts']['regressed']);
    }

    /**
     * A run that broke a row must not then install itself as the thing future
     * runs are measured against.
     */
    public function test_a_failing_run_is_not_promoted_as_the_baseline(): void
    {
        Storage::fake('eval-compare');
        $this->registerDataset('gate.nopromote');
        $this->bindSut(fn (array $input): string => $input['q'] === 'a' ? 'A' : 'B');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.nopromote',
            '--json' => true,
            '--out' => 'green.json',
            '--promote-baseline' => true,
        ])->assertExitCode(0);

        $this->bindSut(static fn (): string => 'WRONG');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.nopromote',
            '--json' => true,
            '--out' => 'red.json',
            '--compare' => 'baseline',
            '--promote-baseline' => true,
        ])
            ->expectsOutputToContain('Not promoting a baseline')
            ->assertExitCode(1);

        $this->assertSame(
            'eval-harness/reports/green.json',
            $this->store()->pointer('gate.nopromote')['report_path'],
        );
    }

    public function test_an_invalid_max_regressions_value_is_rejected(): void
    {
        Storage::fake('eval-compare');
        $this->registerDataset('gate.badflag');
        $this->bindSut(static fn (): string => 'A');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.badflag',
            '--compare' => 'baseline',
            '--max-regressions' => 'lots',
        ])
            ->expectsOutputToContain('--max-regressions option requires a non-negative integer')
            ->assertExitCode(1);
    }

    public function test_an_invalid_compare_epsilon_is_rejected(): void
    {
        Storage::fake('eval-compare');
        $this->registerDataset('gate.badepsilon');
        $this->bindSut(static fn (): string => 'A');

        $this->artisan('eval-harness:run', [
            'dataset' => 'gate.badepsilon',
            '--compare' => 'baseline',
            '--compare-epsilon' => '4',
        ])
            ->expectsOutputToContain('--compare-epsilon option requires a number between 0 and 1')
            ->assertExitCode(1);
    }

    /**
     * @return array<string, mixed>
     */
    private function comparisonPayload(string $path): array
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode(
            (string) Storage::disk('eval-compare')->get($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return $payload;
    }

    private function store(): BaselineStore
    {
        /** @var BaselineStore $store */
        $store = $this->app->make(BaselineStore::class);

        return $store;
    }

    private function registerDataset(string $name): void
    {
        /** @var EvalEngine $engine */
        $engine = $this->app->make(EvalEngine::class);

        $engine->dataset($name)
            ->withSamples([
                new DatasetSample(id: 's1', input: ['q' => 'a'], expectedOutput: 'A'),
                new DatasetSample(id: 's2', input: ['q' => 'b'], expectedOutput: 'B'),
            ])
            ->withMetrics(['exact-match'])
            ->register();
    }

    private function bindSut(callable $sut): void
    {
        $this->app->bind('eval-harness.sut', static fn () => $sut);
    }
}
