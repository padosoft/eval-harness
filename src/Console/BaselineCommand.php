<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console;

use Illuminate\Console\Command;
use Padosoft\EvalHarness\Regression\BaselineStore;

/**
 * Artisan entry point: `php artisan eval-harness:baseline <dataset>`.
 *
 * A baseline is the run a dataset is measured against. This command promotes
 * one, shows the current one, or clears it:
 *
 *   php artisan eval-harness:baseline rag.factuality --report=2026-08-24-run.json
 *   php artisan eval-harness:baseline rag.factuality --show
 *   php artisan eval-harness:baseline rag.factuality --clear
 *
 * With no options it promotes the most recent stored report for the dataset,
 * which is the common case right after a run somebody is happy with.
 *
 * Promotion is a pointer write, not a copy: the report artifact stays where it
 * is, so a baseline cannot drift from the run it claims to describe, and
 * getting it wrong costs one more command rather than a lost artifact.
 */
final class BaselineCommand extends Command
{
    /** @var string */
    protected $signature = 'eval-harness:baseline
        {dataset : Dataset name the baseline belongs to}
        {--report= : Report artifact path on the reports disk; defaults to the most recent report for this dataset}
        {--show : Print the current baseline pointer and exit}
        {--clear : Remove the baseline pointer for this dataset}';

    /** @var string */
    protected $description = 'Promote, inspect, or clear the baseline run a dataset is compared against';

    public function handle(BaselineStore $baselines): int
    {
        $dataset = (string) $this->argument('dataset');

        if ((bool) $this->option('clear')) {
            return $this->clear($baselines, $dataset);
        }

        if ((bool) $this->option('show')) {
            return $this->show($baselines, $dataset);
        }

        return $this->promote($baselines, $dataset);
    }

    private function promote(BaselineStore $baselines, string $dataset): int
    {
        $report = $this->option('report');
        $path = is_string($report) && $report !== ''
            ? $report
            : $baselines->latestReportPath($dataset);

        if ($path === null) {
            $this->error(sprintf(
                "No stored report found for dataset '%s'. Run an eval with --out=<file> first, or pass --report=<path>.",
                $dataset,
            ));

            return self::FAILURE;
        }

        $payload = $baselines->readReport($path);

        if ($payload === null) {
            $this->error(sprintf('Report [%s] could not be read as JSON from the reports disk.', $path));

            return self::FAILURE;
        }

        $reportDataset = $payload['dataset'] ?? null;

        // Promoting a report from a different dataset would silently make every
        // later comparison meaningless — the rows would never join, so every row
        // would read as "added" and no regression could ever be detected.
        if (is_string($reportDataset) && $reportDataset !== $dataset) {
            $this->error(sprintf(
                "Report [%s] belongs to dataset '%s', not '%s'. Refusing to promote it.",
                $path,
                $reportDataset,
                $dataset,
            ));

            return self::FAILURE;
        }

        $pointer = $baselines->promote($dataset, $path, $payload);

        $this->info(sprintf("Baseline for '%s' is now [%s].", $dataset, $path));
        $this->summary($pointer);

        return self::SUCCESS;
    }

    private function show(BaselineStore $baselines, string $dataset): int
    {
        $pointer = $baselines->pointer($dataset);

        if ($pointer === null) {
            $this->warn(sprintf("No baseline promoted for dataset '%s'.", $dataset));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            "Baseline for '%s': [%s] promoted %s.",
            $dataset,
            is_string($pointer['report_path'] ?? null) ? $pointer['report_path'] : 'unknown',
            is_string($pointer['promoted_at'] ?? null) ? $pointer['promoted_at'] : 'at an unknown time',
        ));
        $this->summary($pointer);

        if ($baselines->report($dataset) === null) {
            $this->warn('The report this baseline points at is no longer readable; comparisons will be skipped until it is promoted again.');
        }

        return self::SUCCESS;
    }

    private function clear(BaselineStore $baselines, string $dataset): int
    {
        if ($baselines->clear($dataset)) {
            $this->info(sprintf("Baseline for '%s' cleared.", $dataset));

            return self::SUCCESS;
        }

        $this->warn(sprintf("No baseline to clear for dataset '%s'.", $dataset));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $pointer
     */
    private function summary(array $pointer): void
    {
        $summary = $pointer['summary'] ?? null;

        if (! is_array($summary)) {
            return;
        }

        $rows = [];
        foreach (['macro_f1', 'pass_rate', 'repetitions', 'total_samples', 'total_executions', 'total_failures'] as $key) {
            $value = $summary[$key] ?? null;
            if ($value === null) {
                continue;
            }
            $rows[] = [$key, is_float($value) ? sprintf('%.4f', $value) : (string) $value];
        }

        if ($rows !== []) {
            $this->table(['field', 'value'], $rows);
        }
    }
}
