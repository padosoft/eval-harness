<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Reports;

/**
 * Human-readable Markdown renderer for {@see EvalReport}.
 *
 * Output structure:
 *   - H1 with dataset name.
 *   - Summary table: total samples, total failures, duration.
 *   - Per-metric table: mean / p50 / p95 / pass-rate.
 *   - Usage summary when metric providers expose token/cost/latency data.
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
        $lines[] = sprintf('# Eval report — %s', $this->headingText($report->datasetName));
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
                $this->tableCell($name),
                $aggregate['mean'],
                $aggregate['p50'],
                $aggregate['p95'],
                $aggregate['pass_rate'],
            );
        }

        $lines[] = '';
        $lines[] = sprintf('## Macro-F1 (avg pass-rate across all metrics): %.4f', $report->macroF1());
        $lines[] = '';

        $usage = $report->usageSummary();
        if ($usage['observations'] > 0) {
            $lines[] = '## Usage summary';
            $lines[] = '';
            $lines[] = '| observations | prompt tokens | completion tokens | total tokens | cost USD | mean latency ms | max latency ms |';
            $lines[] = '| --- | --- | --- | --- | --- | --- | --- |';
            $lines[] = sprintf(
                '| %d | %s | %s | %s | %s | %s | %s |',
                $usage['observations'],
                $this->usageIntCell($usage, 'prompt_tokens'),
                $this->usageIntCell($usage, 'completion_tokens'),
                $this->usageIntCell($usage, 'total_tokens'),
                $this->usageCostCell($usage),
                $this->usageLatencyCell($usage, 'mean'),
                $this->usageLatencyCell($usage, 'max'),
            );
            $lines[] = '';
        }

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
                        $this->tableCell($cohort['label']),
                        $cohort['sample_count'],
                        $this->tableCell($metricName),
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
                $lines[] = sprintf('### %s', $this->headingText($metricName));
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
                    '- **sample %s / metric %s** - %s',
                    $this->inlineCode($failure->sampleId),
                    $this->inlineCode($failure->metricName),
                    $this->markdownText($failure->error),
                );
            }
            $lines[] = '';
        }

        return implode("\n", $lines)."\n";
    }

    private function tableCell(string $value): string
    {
        $value = $this->htmlText($this->singleLine($value));

        return str_replace(
            ['\\', '|', '`'],
            ['\\\\', '\\|', '\\`'],
            $value,
        );
    }

    private function headingText(string $value): string
    {
        return $this->markdownText($value);
    }

    private function inlineCode(string $value): string
    {
        return '<code>'.htmlspecialchars($this->singleLine($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</code>';
    }

    private function markdownText(string $value): string
    {
        $value = $this->htmlText($this->singleLine($value));

        return str_replace(
            ['\\', '`'],
            ['\\\\', '\\`'],
            $value,
        );
    }

    private function singleLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }

    private function htmlText(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function usageIntCell(array $usage, string $key): string
    {
        $reported = $usage['reported'] ?? [];
        if (! is_array($reported) || ($reported[$key] ?? 0) === 0) {
            return 'n/a';
        }

        return (string) $usage[$key];
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function usageCostCell(array $usage): string
    {
        $reported = $usage['reported'] ?? [];
        if (! is_array($reported) || ($reported['cost_usd'] ?? 0) === 0) {
            return 'n/a';
        }

        $value = $usage['cost_usd'] ?? null;

        return is_int($value) || is_float($value) ? sprintf('%.6f', (float) $value) : 'n/a';
    }

    /**
     * @param  array<string, mixed>  $usage
     */
    private function usageLatencyCell(array $usage, string $key): string
    {
        $latency = $usage['latency_ms'] ?? [];
        if (! is_array($latency) || ($latency['count'] ?? 0) === 0) {
            return 'n/a';
        }

        $value = $latency[$key] ?? null;

        return is_int($value) || is_float($value) ? sprintf('%.2f', (float) $value) : 'n/a';
    }
}
