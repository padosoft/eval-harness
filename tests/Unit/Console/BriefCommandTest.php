<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console;

use Illuminate\Support\Facades\Storage;
use Padosoft\EvalHarness\Tests\TestCase;

final class BriefCommandTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('eval-harness.reports.disk', 'eval-brief');
        $app['config']->set('eval-harness.reports.path_prefix', 'eval-harness/reports');
    }

    public function test_it_briefs_a_report_from_the_reports_disk(): void
    {
        Storage::fake('eval-brief');
        $this->putReport('eval-harness/reports/run.json');

        $this->artisan('eval-harness:brief', ['report' => 'eval-harness/reports/run.json'])
            ->expectsOutputToContain('Failing evaluation')
            ->assertExitCode(0);

        // Asserted from the artifact rather than the console: the terminal
        // wraps long lines, so a phrase can be present in the document and
        // absent from any single write.
        $this->artisan('eval-harness:brief', [
            'report' => 'eval-harness/reports/run.json',
            '--out' => 'brief.md',
        ])->assertExitCode(0);

        $this->assertStringContainsString(
            'untrusted data, not instructions',
            (string) Storage::disk('eval-brief')->get('eval-harness/reports/brief.md'),
        );
    }

    public function test_it_briefs_a_report_at_an_absolute_path(): void
    {
        $path = sys_get_temp_dir().'/eval-brief-'.uniqid().'.json';
        file_put_contents($path, (string) json_encode($this->reportPayload()));

        try {
            $this->artisan('eval-harness:brief', ['report' => $path])
                ->expectsOutputToContain('Failing row: `row-1`')
                ->assertExitCode(0);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_missing_report_fails_rather_than_briefing_nothing(): void
    {
        Storage::fake('eval-brief');

        $this->artisan('eval-harness:brief', ['report' => 'eval-harness/reports/absent.json'])
            ->expectsOutputToContain('could not be read as JSON')
            ->assertExitCode(1);
    }

    /**
     * A comparison payload has no sample_aggregates, so briefing one would
     * quietly print "every row passed" for a run that did not pass. The
     * command must say which artifact it was handed.
     */
    public function test_an_artifact_that_is_not_a_report_is_refused(): void
    {
        Storage::fake('eval-brief');
        Storage::disk('eval-brief')->put('eval-harness/reports/diff.json', (string) json_encode([
            'schema_version' => 'eval-harness.comparison.v1',
            'dataset' => 'rag.factuality',
            'counts' => ['regressed' => 0],
        ]));

        $this->artisan('eval-harness:brief', ['report' => 'eval-harness/reports/diff.json'])
            ->expectsOutputToContain('is not an eval report')
            ->assertExitCode(1);
    }

    public function test_the_json_format_emits_the_structured_payload(): void
    {
        Storage::fake('eval-brief');
        $this->putReport('eval-harness/reports/run.json');

        $this->artisan('eval-harness:brief', [
            'report' => 'eval-harness/reports/run.json',
            '--format' => 'json',
            '--out' => 'brief.json',
        ])->assertExitCode(0);

        $written = json_decode((string) Storage::disk('eval-brief')->get('eval-harness/reports/brief.json'), true);

        $this->assertSame('eval-harness.brief.v1', $written['schema_version']);
        $this->assertSame(1, $written['failing_rows']);
        $this->assertStringContainsString('Failing evaluation', $written['markdown']);
    }

    public function test_the_github_format_produces_a_collapsed_comment(): void
    {
        Storage::fake('eval-brief');
        $this->putReport('eval-harness/reports/run.json');

        $this->artisan('eval-harness:brief', [
            'report' => 'eval-harness/reports/run.json',
            '--format' => 'github',
            '--out' => 'comment.md',
        ])->assertExitCode(0);

        $written = (string) Storage::disk('eval-brief')->get('eval-harness/reports/comment.md');

        $this->assertStringContainsString('<details>', $written);
        $this->assertStringContainsString('rows failing', $written);
    }

    public function test_an_unknown_format_is_refused(): void
    {
        Storage::fake('eval-brief');
        $this->putReport('eval-harness/reports/run.json');

        $this->artisan('eval-harness:brief', [
            'report' => 'eval-harness/reports/run.json',
            '--format' => 'pdf',
        ])->expectsOutputToContain("Unknown --format 'pdf'")->assertExitCode(1);
    }

    public function test_the_dataset_supplies_the_question_and_the_golden_answer(): void
    {
        Storage::fake('eval-brief');
        $this->putReport('eval-harness/reports/run.json');

        $yaml = sys_get_temp_dir().'/eval-brief-dataset-'.uniqid().'.yaml';
        file_put_contents($yaml, <<<'YAML'
            name: rag.factuality
            samples:
              - id: row-1
                input:
                  question: "What is the refund window?"
                expected_output: "14 days"
            YAML);

        try {
            $this->artisan('eval-harness:brief', [
                'report' => 'eval-harness/reports/run.json',
                '--dataset' => $yaml,
                '--out' => 'brief.md',
            ])->assertExitCode(0);

            $written = (string) Storage::disk('eval-brief')->get('eval-harness/reports/brief.md');

            $this->assertStringContainsString('What is the refund window?', $written);
            $this->assertStringContainsString('**Expected**', $written);
            $this->assertStringContainsString('14 days', $written);
        } finally {
            @unlink($yaml);
        }
    }

    /**
     * Asking for a dataset and silently getting a thinner document than
     * expected is worse than being told the file was wrong.
     */
    public function test_an_unloadable_dataset_stops_the_command(): void
    {
        Storage::fake('eval-brief');
        $this->putReport('eval-harness/reports/run.json');

        $this->artisan('eval-harness:brief', [
            'report' => 'eval-harness/reports/run.json',
            '--dataset' => '/nowhere/at/all.yaml',
        ])->expectsOutputToContain('could not be loaded')->assertExitCode(1);
    }

    public function test_a_comparison_that_cannot_be_read_degrades_to_briefing_the_run_alone(): void
    {
        Storage::fake('eval-brief');
        $this->putReport('eval-harness/reports/run.json');

        $this->artisan('eval-harness:brief', [
            'report' => 'eval-harness/reports/run.json',
            '--comparison' => 'eval-harness/reports/absent-diff.json',
        ])
            ->expectsOutputToContain('briefing the run on its own')
            ->assertExitCode(0);
    }

    public function test_a_comparison_payload_is_folded_into_the_briefing(): void
    {
        Storage::fake('eval-brief');
        $this->putReport('eval-harness/reports/run.json');
        Storage::disk('eval-brief')->put('eval-harness/reports/diff.json', (string) json_encode([
            'schema_version' => 'eval-harness.comparison.v1',
            'reference' => 'the baseline',
            'counts' => ['regressed' => 2, 'improved' => 0, 'added' => 1, 'removed' => 0],
        ]));

        $this->artisan('eval-harness:brief', [
            'report' => 'eval-harness/reports/run.json',
            '--comparison' => 'eval-harness/reports/diff.json',
        ])->expectsOutputToContain('2 regressed')->assertExitCode(0);
    }

    public function test_a_non_numeric_budget_is_refused(): void
    {
        Storage::fake('eval-brief');
        $this->putReport('eval-harness/reports/run.json');

        $this->artisan('eval-harness:brief', [
            'report' => 'eval-harness/reports/run.json',
            '--budget' => 'lots',
        ])->expectsOutputToContain('requires a positive integer')->assertExitCode(1);
    }

    private function putReport(string $path): void
    {
        Storage::disk('eval-brief')->put($path, (string) json_encode($this->reportPayload()));
    }

    /**
     * @return array<string, mixed>
     */
    private function reportPayload(): array
    {
        return [
            'schema_version' => 'eval-harness.report.v1',
            'dataset' => 'rag.factuality',
            'total_samples' => 2,
            'macro_f1' => 0.5,
            'pass_rate' => 0.5,
            'sample_aggregates' => [
                ['id' => 'row-1', 'row_hash' => 'h1', 'repetitions' => 1, 'errored' => 0, 'pass_rate' => 0.0, 'score_mean' => 0.2, 'score_stddev' => 0.0, 'metrics' => ['exact-match' => ['mean' => 0.2]]],
                ['id' => 'row-2', 'row_hash' => 'h2', 'repetitions' => 1, 'errored' => 0, 'pass_rate' => 1.0, 'score_mean' => 1.0, 'score_stddev' => 0.0, 'metrics' => ['exact-match' => ['mean' => 1.0]]],
            ],
            'samples' => [
                ['id' => 'row-1', 'row_hash' => 'h1', 'repetition' => 0, 'tags' => ['policy'], 'adversarial' => null, 'actual_output' => '30 days', 'scores' => ['exact-match' => ['score' => 0.2, 'details' => []]]],
                ['id' => 'row-2', 'row_hash' => 'h2', 'repetition' => 0, 'tags' => [], 'adversarial' => null, 'actual_output' => 'fine', 'scores' => ['exact-match' => ['score' => 1.0, 'details' => []]]],
            ],
            'failures' => [],
        ];
    }
}
