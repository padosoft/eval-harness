<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Reports;

/**
 * Human-readable Markdown renderer for {@see EvalReport}.
 *
 * Output structure:
 *   - H1 with dataset name + run timestamp.
 *   - Summary table: total samples, total failures, duration.
 *   - Per-metric table: mean / p50 / p95 / pass-rate.
 *   - Cohort table by metadata.tags.
 *   - Per-metric score histograms.
 *   - Failure list (if any).
 *
 * Designed to be diffed across runs — section ordering is stable
 * so a `git diff` between two reports highlights the regression
 * directly.
 */
final class MarkdownReportRenderer
{
    public function render(EvalReport $report): string
    {
        $lines = [];
        $lines[] = sprintf('# Eval report — %s', $report->datasetName);
        $lines[] = '';
        $lines[] = sprintf(
            '_Run completed in %.2fs over %d samples (%d failures captured)._',
            $report->durationSeconds(),
            $report->totalSamples(),
            $report->totalFailures(),
        );
        $lines[] = '';

        $lines[] = '## Summary';
        $lines[] = '';
        $lines[] = '| total samples | total failures | duration seconds |';
        $lines[] = '| --- | --- | --- |';
        $lines[] = sprintf(
            '| %d | %d | %.2f |',
            $report->totalSamples(),
            $report->totalFailures(),
            $report->durationSeconds(),
        );
        $lines[] = '';

        $lines[] = '## Per-metric aggregates';
        $lines[] = '';
        $lines[] = '| metric | mean | p50 | p95 | pass-rate (>= 0.5) |';
        $lines[] = '| --- | --- | --- | --- | --- |';

        foreach ($report->metricNames() as $name) {
            $aggregate = $report->metricAggregate($name);
            $lines[] = sprintf(
                '| %s | %.4f | %.4f | %.4f | %.4f |',
                $name,
                $aggregate['mean'],
                $aggregate['p50'],
                $aggregate['p95'],
                $aggregate['pass_rate'],
            );
        }

        $lines[] = '';
        $lines[] = sprintf('## Macro-F1 (avg pass-rate across all metrics): %.4f', $report->macroF1());
        $lines[] = '';

        $cohorts = $report->cohortSummaries();
        if ($cohorts !== []) {
            $lines[] = '## Cohorts by metadata.tags';
            $lines[] = '';
            $lines[] = '| cohort | samples | metric | mean | p50 | p95 | pass-rate (>= 0.5) |';
            $lines[] = '| --- | --- | --- | --- | --- | --- | --- |';

            foreach ($cohorts as $cohort) {
                foreach ($cohort['metrics'] as $metricName => $aggregate) {
                    $lines[] = sprintf(
                        '| %s | %d | %s | %.4f | %.4f | %.4f | %.4f |',
                        $cohort['label'],
                        $cohort['sample_count'],
                        $metricName,
                        $aggregate['mean'],
                        $aggregate['p50'],
                        $aggregate['p95'],
                        $aggregate['pass_rate'],
                    );
                }
            }

            $lines[] = '';
        }

        $distributions = $report->metricDistributions();
        if ($distributions !== []) {
            $lines[] = '## Score histograms';
            $lines[] = '';

            foreach ($distributions as $metricName => $histogram) {
                $lines[] = sprintf('### %s', $metricName);
                $lines[] = '';
                $lines[] = '| score range | count |';
                $lines[] = '| --- | --- |';

                foreach ($histogram as $bucket) {
                    $lines[] = sprintf(
                        '| %.1f-%.1f%s | %d |',
                        $bucket['min'],
                        $bucket['max'],
                        $bucket['max'] >= 1.0 ? ' inclusive' : '',
                        $bucket['count'],
                    );
                }

                $lines[] = '';
            }
        }

        if ($report->totalFailures() > 0) {
            $lines[] = '## Failures';
            $lines[] = '';
            foreach ($report->failures as $failure) {
                $lines[] = sprintf(
                    '- **sample `%s` / metric `%s`** — %s',
                    $failure->sampleId,
                    $failure->metricName,
                    $failure->error,
                );
            }
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }
}
