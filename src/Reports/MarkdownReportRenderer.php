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

        $lines[] = '## Per-metric aggregates';
        $lines[] = '';
        $lines[] = '| metric | mean | p50 | p95 | pass-rate (>= 0.5) |';
        $lines[] = '| --- | --- | --- | --- | --- |';

        foreach ($report->metricNames() as $name) {
            $lines[] = sprintf(
                '| %s | %.4f | %.4f | %.4f | %.4f |',
                $name,
                $report->meanScore($name),
                $report->percentile($name, 50.0),
                $report->percentile($name, 95.0),
                $report->macroF1($name),
            );
        }

        $lines[] = '';
        $lines[] = sprintf('## Macro-F1 (avg pass-rate across all metrics): %.4f', $report->macroF1());
        $lines[] = '';

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
