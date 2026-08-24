<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console;

use Illuminate\Support\Facades\Storage;
use Padosoft\EvalHarness\Regression\BaselineStore;
use Padosoft\EvalHarness\Tests\TestCase;

final class BaselineCommandTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.reports.disk', 'eval-baseline-cli');
        $app['config']->set('eval-harness.reports.path_prefix', 'eval-harness/reports');
    }

    public function test_promoting_without_a_report_uses_the_most_recent_one(): void
    {
        Storage::fake('eval-baseline-cli');
        $this->putReport('eval-harness/reports/old.json', 'rag', 100.0);
        $this->putReport('eval-harness/reports/new.json', 'rag', 200.0);

        $this->artisan('eval-harness:baseline', ['dataset' => 'rag'])
            ->expectsOutputToContain('eval-harness/reports/new.json')
            ->assertExitCode(0);

        $pointer = $this->store()->pointer('rag');

        $this->assertSame('eval-harness/reports/new.json', $pointer['report_path']);
    }

    public function test_promoting_an_explicit_report(): void
    {
        Storage::fake('eval-baseline-cli');
        $this->putReport('eval-harness/reports/old.json', 'rag', 100.0);
        $this->putReport('eval-harness/reports/new.json', 'rag', 200.0);

        $this->artisan('eval-harness:baseline', [
            'dataset' => 'rag',
            '--report' => 'eval-harness/reports/old.json',
        ])->assertExitCode(0);

        $this->assertSame('eval-harness/reports/old.json', $this->store()->pointer('rag')['report_path']);
    }

    /**
     * Promoting a report from another dataset would make every later
     * comparison meaningless: no row would ever join, so nothing could ever be
     * detected as a regression.
     */
    public function test_promoting_a_report_from_another_dataset_is_refused(): void
    {
        Storage::fake('eval-baseline-cli');
        $this->putReport('eval-harness/reports/other.json', 'different-dataset', 100.0);

        $this->artisan('eval-harness:baseline', [
            'dataset' => 'rag',
            '--report' => 'eval-harness/reports/other.json',
        ])
            ->expectsOutputToContain("belongs to dataset 'different-dataset'")
            ->assertExitCode(1);

        $this->assertNull($this->store()->pointer('rag'));
    }

    public function test_promoting_without_any_stored_report_fails_with_guidance(): void
    {
        Storage::fake('eval-baseline-cli');

        $this->artisan('eval-harness:baseline', ['dataset' => 'rag'])
            ->expectsOutputToContain('No stored report found')
            ->assertExitCode(1);
    }

    public function test_promoting_an_unreadable_report_fails(): void
    {
        Storage::fake('eval-baseline-cli');
        Storage::disk('eval-baseline-cli')->put('eval-harness/reports/broken.json', 'not json');

        $this->artisan('eval-harness:baseline', [
            'dataset' => 'rag',
            '--report' => 'eval-harness/reports/broken.json',
        ])
            ->expectsOutputToContain('could not be read as JSON')
            ->assertExitCode(1);
    }

    public function test_show_prints_the_current_pointer(): void
    {
        Storage::fake('eval-baseline-cli');
        $this->putReport('eval-harness/reports/run.json', 'rag', 100.0);
        $this->store()->promote('rag', 'eval-harness/reports/run.json', ['dataset' => 'rag']);

        $this->artisan('eval-harness:baseline', ['dataset' => 'rag', '--show' => true])
            ->expectsOutputToContain('eval-harness/reports/run.json')
            ->assertExitCode(0);
    }

    public function test_show_warns_when_nothing_is_promoted(): void
    {
        Storage::fake('eval-baseline-cli');

        $this->artisan('eval-harness:baseline', ['dataset' => 'rag', '--show' => true])
            ->expectsOutputToContain('No baseline promoted')
            ->assertExitCode(0);
    }

    public function test_show_warns_when_the_report_is_gone(): void
    {
        Storage::fake('eval-baseline-cli');
        $this->putReport('eval-harness/reports/run.json', 'rag', 100.0);
        $this->store()->promote('rag', 'eval-harness/reports/run.json', ['dataset' => 'rag']);
        Storage::disk('eval-baseline-cli')->delete('eval-harness/reports/run.json');

        $this->artisan('eval-harness:baseline', ['dataset' => 'rag', '--show' => true])
            ->expectsOutputToContain('no longer readable')
            ->assertExitCode(0);
    }

    public function test_clear_removes_the_pointer(): void
    {
        Storage::fake('eval-baseline-cli');
        $this->putReport('eval-harness/reports/run.json', 'rag', 100.0);
        $this->store()->promote('rag', 'eval-harness/reports/run.json', ['dataset' => 'rag']);

        $this->artisan('eval-harness:baseline', ['dataset' => 'rag', '--clear' => true])
            ->expectsOutputToContain('cleared')
            ->assertExitCode(0);

        $this->assertNull($this->store()->pointer('rag'));
    }

    /**
     * A payload that cannot prove it is a report of this dataset cannot prove
     * it will ever join a row — and a baseline that joins nothing reads as
     * zero regressions and passes the gate.
     */
    public function test_promoting_an_artifact_that_is_not_a_report_is_refused(): void
    {
        Storage::fake('eval-baseline-cli');
        Storage::disk('eval-baseline-cli')->put('eval-harness/reports/diff.json', (string) json_encode([
            'schema_version' => 'eval-harness.comparison.v1',
            'dataset' => 'rag',
            'counts' => ['regressed' => 0],
        ]));

        $this->artisan('eval-harness:baseline', [
            'dataset' => 'rag',
            '--report' => 'eval-harness/reports/diff.json',
        ])
            ->expectsOutputToContain('does not declare the report contract')
            ->assertExitCode(1);

        $this->assertNull($this->store()->pointer('rag'));
    }

    public function test_promoting_a_report_without_a_dataset_name_is_refused(): void
    {
        Storage::fake('eval-baseline-cli');
        Storage::disk('eval-baseline-cli')->put('eval-harness/reports/anonymous.json', (string) json_encode([
            'schema_version' => 'eval-harness.report.v1',
            'macro_f1' => 0.9,
            'sample_aggregates' => [],
        ]));

        $this->artisan('eval-harness:baseline', [
            'dataset' => 'rag',
            '--report' => 'eval-harness/reports/anonymous.json',
        ])
            ->expectsOutputToContain('Refusing to promote it')
            ->assertExitCode(1);

        $this->assertNull($this->store()->pointer('rag'));
    }

    private function store(): BaselineStore
    {
        /** @var BaselineStore $store */
        $store = $this->app->make(BaselineStore::class);

        return $store;
    }

    private function putReport(string $path, string $dataset, float $finishedAt): void
    {
        Storage::disk('eval-baseline-cli')->put($path, (string) json_encode([
            'schema_version' => 'eval-harness.report.v1',
            'dataset' => $dataset,
            'macro_f1' => 0.9,
            'pass_rate' => 1.0,
            'repetitions' => 1,
            'total_executions' => 2,
            'total_samples' => 2,
            'total_failures' => 0,
            'finished_at' => $finishedAt,
            'sample_aggregates' => [],
        ]));
    }
}
