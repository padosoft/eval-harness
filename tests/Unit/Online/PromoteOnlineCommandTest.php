<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Online;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\Online\OnlineScore;
use Padosoft\EvalHarness\Tests\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PromoteOnlineCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The whole point: a question a real user asked, on which the pipeline
     * really failed, becomes a permanent regression test.
     */
    public function test_it_promotes_failing_interactions_into_a_dataset(): void
    {
        $this->record('what is the refund window', '30 days from delivery', '14 days', passed: false, score: 0.2);
        $this->record('who signs the invoice', 'the finance lead', 'the finance lead', passed: true, score: 1.0);

        $path = $this->tempPath();

        try {
            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq',
                '--out' => $path,
                '--raw-path' => true,
            ])->assertExitCode(0);

            $document = Yaml::parse((string) file_get_contents($path));

            $this->assertSame('rag.faq', $document['name']);
            $this->assertSame(['exact-match'], $document['metrics']);
            $this->assertCount(1, $document['samples'], 'only the failure is promoted');
            $this->assertSame('what is the refund window', $document['samples'][0]['input']['question']);
            $this->assertSame('30 days from delivery', $document['samples'][0]['expected_output']);
            $this->assertSame(['promoted-from-production'], $document['samples'][0]['metadata']['tags']);
            $this->assertSame(0.2, $document['samples'][0]['metadata']['online_score']);
        } finally {
            @unlink($path);
        }
    }

    public function test_passing_interactions_are_promoted_only_when_asked(): void
    {
        $this->record('who signs the invoice', 'the finance lead', 'the finance lead', passed: true, score: 1.0);
        $path = $this->tempPath();

        try {
            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq',
                '--all' => true,
                '--out' => $path,
                '--raw-path' => true,
            ])->assertExitCode(0);

            $document = Yaml::parse((string) file_get_contents($path));

            $this->assertCount(1, $document['samples']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * A nightly promotion promotes the same recurring failure every night, so
     * the dedup is not a nicety.
     */
    public function test_a_row_already_in_the_dataset_is_not_promoted_twice(): void
    {
        $this->record('what is the refund window', '30 days from delivery', '14 days', passed: false, score: 0.2);

        $existing = $this->tempPath();
        file_put_contents($existing, Yaml::dump([
            'name' => 'rag.faq',
            'samples' => [[
                'id' => 'hand-written',
                'input' => ['question' => 'what is the refund window'],
                'expected_output' => '30 days from delivery',
            ]],
        ]));

        try {
            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq',
                '--merge' => $existing,
            ])
                ->expectsOutputToContain('0 promoted')
                ->assertExitCode(0);

            $document = Yaml::parse((string) file_get_contents($existing));

            $this->assertCount(1, $document['samples'], 'the file is left alone');
            $this->assertSame('hand-written', $document['samples'][0]['id']);
        } finally {
            @unlink($existing);
        }
    }

    public function test_merging_appends_and_keeps_the_existing_rows_first(): void
    {
        $this->record('a new failure', 'the right answer', 'the wrong one', passed: false, score: 0.1);

        $existing = $this->tempPath();
        file_put_contents($existing, Yaml::dump([
            'name' => 'rag.faq',
            'metrics' => ['exact-match'],
            'samples' => [[
                'id' => 'hand-written',
                'input' => ['question' => 'an older question'],
                'expected_output' => 'an older answer',
                'metadata' => ['tags' => ['curated']],
            ]],
        ]));

        try {
            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq',
                '--merge' => $existing,
            ])->assertExitCode(0);

            $document = Yaml::parse((string) file_get_contents($existing));

            $this->assertCount(2, $document['samples']);
            $this->assertSame('hand-written', $document['samples'][0]['id']);
            $this->assertSame(['curated'], $document['samples'][0]['metadata']['tags']);
            $this->assertStringStartsWith('online-', $document['samples'][1]['id']);

            // The merged file must still load as a dataset, or the next run of
            // this command cannot read what the last one wrote.
            $parsed = (new YamlDatasetLoader)->loadFile($existing);
            $this->assertCount(2, $parsed->samples);
        } finally {
            @unlink($existing);
        }
    }

    /**
     * Promoting raw production text copies it into a file that gets committed,
     * and a repository is forever.
     */
    public function test_unredacted_interactions_are_skipped_and_the_skip_is_announced(): void
    {
        $this->record('sensitive question', 'answer', 'wrong', passed: false, score: 0.1, redactor: null);
        $path = $this->tempPath();

        try {
            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq',
                '--out' => $path,
                '--raw-path' => true,
            ])
                ->expectsOutputToContain('no redactor bound')
                ->assertExitCode(0);

            $this->assertFileDoesNotExist($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_unredacted_interactions_can_be_promoted_deliberately(): void
    {
        $this->record('sensitive question', 'answer', 'wrong', passed: false, score: 0.1, redactor: null);
        $path = $this->tempPath();

        try {
            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq',
                '--allow-unredacted' => true,
                '--out' => $path,
                '--raw-path' => true,
            ])->assertExitCode(0);

            $document = Yaml::parse((string) file_get_contents($path));

            $this->assertCount(1, $document['samples']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * An upgraded install has scores with no retained text; promoting them
     * would produce a dataset of empty questions.
     */
    public function test_scores_without_retained_text_are_skipped_with_an_explanation(): void
    {
        OnlineScore::create([
            'dataset' => 'rag.faq',
            'sample_id' => 'legacy',
            'metric' => 'llm-as-judge',
            'score' => 0.1,
            'passed' => false,
            'judged_at' => Carbon::now(),
        ]);

        $this->artisan('eval-harness:promote-online', ['dataset' => 'rag.faq'])
            ->expectsOutputToContain('no retained text')
            ->assertExitCode(0);
    }

    public function test_the_score_ceiling_filters_the_batch(): void
    {
        $this->record('barely wrong', 'right', 'nearly', passed: false, score: 0.6);
        $this->record('badly wrong', 'right', 'no', passed: false, score: 0.05);

        $path = $this->tempPath();

        try {
            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq',
                '--max-score' => '0.3',
                '--out' => $path,
                '--raw-path' => true,
            ])->assertExitCode(0);

            $document = Yaml::parse((string) file_get_contents($path));

            $this->assertCount(1, $document['samples']);
            $this->assertSame('badly wrong', $document['samples'][0]['input']['question']);
        } finally {
            @unlink($path);
        }
    }

    public function test_the_window_excludes_older_interactions(): void
    {
        $this->record('ancient', 'right', 'wrong', passed: false, score: 0.1, judgedAt: Carbon::now()->subDays(40));
        $this->record('recent', 'right', 'wrong', passed: false, score: 0.1);

        $path = $this->tempPath();

        try {
            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq',
                '--since' => '7',
                '--out' => $path,
                '--raw-path' => true,
            ])->assertExitCode(0);

            $document = Yaml::parse((string) file_get_contents($path));

            $this->assertCount(1, $document['samples']);
            $this->assertSame('recent', $document['samples'][0]['input']['question']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * When a limit truncates the batch, the rows that survive should be the
     * ones the pipeline handled worst.
     */
    public function test_the_limit_keeps_the_worst_scoring_rows(): void
    {
        $this->record('mildly wrong', 'right', 'nearly', passed: false, score: 0.6);
        $this->record('very wrong', 'right', 'no', passed: false, score: 0.01);

        $path = $this->tempPath();

        try {
            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq',
                '--limit' => '1',
                '--out' => $path,
                '--raw-path' => true,
            ])->assertExitCode(0);

            $document = Yaml::parse((string) file_get_contents($path));

            $this->assertSame('very wrong', $document['samples'][0]['input']['question']);
        } finally {
            @unlink($path);
        }
    }

    /**
     * The promoter treats an unreadable merge target as "no existing rows",
     * which is right for a missing file and catastrophic for a corrupt one:
     * writing back would replace a dataset with this run's handful of rows.
     */
    public function test_a_corrupt_merge_target_stops_the_command_rather_than_replacing_it(): void
    {
        $this->record('a failure', 'right', 'wrong', passed: false, score: 0.1);

        $broken = $this->tempPath();
        file_put_contents($broken, "not: a: dataset:\n  - [");

        try {
            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq',
                '--merge' => $broken,
            ])
                ->expectsOutputToContain('would replace it')
                ->assertExitCode(1);

            $this->assertStringContainsString('not: a: dataset', (string) file_get_contents($broken));
        } finally {
            @unlink($broken);
        }
    }

    public function test_a_bad_limit_is_refused(): void
    {
        $this->artisan('eval-harness:promote-online', [
            'dataset' => 'rag.faq',
            '--limit' => 'many',
        ])
            ->expectsOutputToContain('--limit option requires a positive integer')
            ->assertExitCode(1);
    }

    public function test_a_score_ceiling_outside_the_unit_interval_is_refused(): void
    {
        $this->artisan('eval-harness:promote-online', [
            'dataset' => 'rag.faq',
            '--max-score' => '5',
        ])
            ->expectsOutputToContain('between 0 and 1')
            ->assertExitCode(1);
    }

    /**
     * Promoting from two environments, or after a table truncation, must not
     * renumber a dataset somebody has already committed.
     */
    public function test_the_promoted_row_id_comes_from_the_content_not_the_database(): void
    {
        $this->record('stable question', 'stable answer', 'wrong', passed: false, score: 0.1);
        $first = $this->tempPath();
        $second = $this->tempPath();

        try {
            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq', '--out' => $first, '--raw-path' => true,
            ])->assertExitCode(0);

            OnlineScore::query()->delete();
            $this->record('stable question', 'stable answer', 'wrong', passed: false, score: 0.1);

            $this->artisan('eval-harness:promote-online', [
                'dataset' => 'rag.faq', '--out' => $second, '--raw-path' => true,
            ])->assertExitCode(0);

            $idOf = static fn (string $path): string => Yaml::parse((string) file_get_contents($path))['samples'][0]['id'];

            $this->assertSame($idOf($first), $idOf($second));
        } finally {
            @unlink($first);
            @unlink($second);
        }
    }

    private function record(
        string $question,
        string $expected,
        string $actual,
        bool $passed,
        float $score,
        ?string $redactor = 'TestRedactor',
        ?Carbon $judgedAt = null,
    ): void {
        OnlineScore::create([
            'dataset' => 'rag.faq',
            'sample_id' => 'live-'.substr(md5($question), 0, 8),
            'metric' => 'llm-as-judge',
            'score' => $score,
            'passed' => $passed,
            'judge_model' => 'gpt-4o-mini',
            'input' => ['question' => $question],
            'expected_output' => $expected,
            'actual_output' => $actual,
            'redactor' => $redactor,
            'redacted_at' => $redactor === null ? null : Carbon::now(),
            'judged_at' => $judgedAt ?? Carbon::now(),
        ]);
    }

    private function tempPath(): string
    {
        return tempnam(sys_get_temp_dir(), 'eval-promote').'.yaml';
    }
}
