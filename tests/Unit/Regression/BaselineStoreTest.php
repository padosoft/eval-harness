<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Regression;

use Illuminate\Support\Facades\Storage;
use Padosoft\EvalHarness\Regression\BaselineStore;
use Padosoft\EvalHarness\Regression\RegressionSchema;
use Padosoft\EvalHarness\Tests\TestCase;

final class BaselineStoreTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.reports.disk', 'eval-baselines');
        $app['config']->set('eval-harness.reports.path_prefix', 'eval-harness/reports');
    }

    public function test_promoting_writes_a_versioned_pointer(): void
    {
        Storage::fake('eval-baselines');
        $this->putReport('eval-harness/reports/run-1.json', 'rag', macroF1: 0.9, finishedAt: 100.0);

        $store = $this->store();
        $pointer = $store->promote('rag', 'eval-harness/reports/run-1.json', $store->readReport('eval-harness/reports/run-1.json') ?? []);

        $this->assertSame(RegressionSchema::BASELINE_VERSION, $pointer['schema_version']);
        $this->assertSame('eval-harness/reports/run-1.json', $pointer['report_path']);
        $this->assertSame(0.9, $pointer['summary']['macro_f1']);

        Storage::disk('eval-baselines')->assertExists('eval-harness/reports/baselines/rag.json');
    }

    public function test_the_pointer_resolves_back_to_the_report(): void
    {
        Storage::fake('eval-baselines');
        $this->putReport('eval-harness/reports/run-1.json', 'rag', macroF1: 0.75, finishedAt: 100.0);

        $store = $this->store();
        $store->promote('rag', 'eval-harness/reports/run-1.json', ['macro_f1' => 0.75]);

        $report = $store->report('rag');

        $this->assertNotNull($report);
        $this->assertSame('rag', $report['dataset']);
        $this->assertSame(0.75, $report['macro_f1']);
    }

    /**
     * Losing the artifact a baseline points at must degrade the run to
     * "nothing to compare against", never break it.
     */
    public function test_a_pointer_to_a_deleted_report_returns_null(): void
    {
        Storage::fake('eval-baselines');
        $this->putReport('eval-harness/reports/run-1.json', 'rag', 0.75, 100.0);

        $store = $this->store();
        $store->promote('rag', 'eval-harness/reports/run-1.json', ['macro_f1' => 0.75]);
        Storage::disk('eval-baselines')->delete('eval-harness/reports/run-1.json');

        $this->assertNotNull($store->pointer('rag'));
        $this->assertNull($store->report('rag'));
    }

    public function test_no_baseline_returns_null_rather_than_throwing(): void
    {
        Storage::fake('eval-baselines');

        $this->assertNull($this->store()->pointer('never-promoted'));
        $this->assertNull($this->store()->report('never-promoted'));
    }

    public function test_clearing_removes_the_pointer(): void
    {
        Storage::fake('eval-baselines');
        $this->putReport('eval-harness/reports/run-1.json', 'rag', 0.75, 100.0);

        $store = $this->store();
        $store->promote('rag', 'eval-harness/reports/run-1.json', []);

        $this->assertTrue($store->clear('rag'));
        $this->assertNull($store->pointer('rag'));
        $this->assertFalse($store->clear('rag'));
    }

    public function test_latest_report_ignores_other_datasets_and_the_current_run(): void
    {
        Storage::fake('eval-baselines');
        $this->putReport('eval-harness/reports/old.json', 'rag', 0.7, finishedAt: 100.0);
        $this->putReport('eval-harness/reports/new.json', 'rag', 0.8, finishedAt: 200.0);
        $this->putReport('eval-harness/reports/other.json', 'different', 0.9, finishedAt: 300.0);

        $store = $this->store();

        $this->assertSame('eval-harness/reports/new.json', $store->latestReportPath('rag'));
        $this->assertSame(
            'eval-harness/reports/old.json',
            $store->latestReportPath('rag', 'eval-harness/reports/new.json'),
        );
    }

    public function test_latest_report_skips_baseline_pointers(): void
    {
        Storage::fake('eval-baselines');
        $this->putReport('eval-harness/reports/run.json', 'rag', 0.8, finishedAt: 100.0);

        $store = $this->store();
        $store->promote('rag', 'eval-harness/reports/run.json', ['dataset' => 'rag']);

        $this->assertSame('eval-harness/reports/run.json', $store->latestReportPath('rag'));
    }

    /**
     * Dataset names reach this from config and from the CLI, so the filename is
     * built from an allow-list: no input can walk out of the baselines
     * directory.
     */
    public function test_dataset_names_cannot_escape_the_baselines_directory(): void
    {
        $store = $this->store();

        // Separators become underscores and any leading dot is stripped, so
        // neither a traversal nor a hidden file can come out of this.
        $this->assertSame(
            'eval-harness/reports/baselines/_.._etc_passwd.json',
            $store->pointerPath('../../etc/passwd'),
        );
        $this->assertStringStartsWith('eval-harness/reports/baselines/', $store->pointerPath('/absolute/path'));
        $this->assertSame('eval-harness/reports/baselines/dataset.json', $store->pointerPath('...'));
    }

    public function test_a_corrupt_pointer_is_treated_as_absent(): void
    {
        Storage::fake('eval-baselines');
        Storage::disk('eval-baselines')->put('eval-harness/reports/baselines/rag.json', 'not json');

        $this->assertNull($this->store()->pointer('rag'));
    }

    private function store(): BaselineStore
    {
        /** @var BaselineStore $store */
        $store = $this->app->make(BaselineStore::class);

        return $store;
    }

    private function putReport(string $path, string $dataset, float $macroF1, float $finishedAt): void
    {
        Storage::disk('eval-baselines')->put($path, (string) json_encode([
            'schema_version' => 'eval-harness.report.v1',
            'dataset' => $dataset,
            'macro_f1' => $macroF1,
            'pass_rate' => 1.0,
            'repetitions' => 1,
            'total_executions' => 1,
            'finished_at' => $finishedAt,
            'sample_aggregates' => [],
        ]));
    }
}
