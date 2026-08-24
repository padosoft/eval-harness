<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Reports;

/**
 * Machine-readable JSON renderer for {@see EvalReport}.
 *
 * Shape (stable contract — additive only per R27):
 * {
 *   "schema_version": "eval-harness.report.v1",
 *   "dataset_schema_version": "eval-harness.dataset.v1",
 *   "dataset": "rag.factuality.fy2026",
 *   "started_at": 1714600000.123,
 *   "finished_at": 1714600002.456,
 *   "duration_seconds": 2.333,
 *   "total_samples": 10,
 *   "total_executions": 10,
 *   "repetitions": 1,
 *   "total_failures": 0,
 *   "pass_rate": 0.8,
 *   "precision": {"scope": "per_row", "repetitions": 1, "resolution": 1.0, "target_delta": 0.05,
 *                 "target_resolvable": false, "required_repetitions": 60, "within_row_variance": 0.0,
 *                 "run": {"observations": 10, "resolution": 0.3, "target_resolvable": false}, "summary": "..."},
 *   "metrics": {
 *     "exact-match": {"mean": 0.8, "p50": 1.0, "p95": 1.0, "pass_rate": 0.8}
 *   },
 *   "metric_distributions": {
 *     "exact-match": [{"min": 0.0, "max": 0.1, "count": 2}]
 *   },
 *   "usage": {"observations": 1, "prompt_tokens": 10, "completion_tokens": 4, "total_tokens": 14, "cost_usd": 0.0012, "latency_ms": {...}},
 *   "cohorts": [
 *     {"name": "geography", "label": "geography", "is_untagged": false, "sample_count": 4, "metrics": {...}},
 *     {"name": null, "label": "(untagged)", "is_untagged": true, "sample_count": 1, "metrics": {...}}
 *   ],
 *   "adversarial": {
 *     "total_samples": 2,
 *     "categories": [{"category": "prompt-injection", "label": "Prompt injection", "severity": "high", "sample_count": 1, "compliance_frameworks": ["OWASP LLM"], "metrics": {...}}],
 *     "compliance_frameworks": [{"framework": "OWASP LLM", "sample_count": 2, "categories": ["prompt-injection"]}]
 *   },
 *   "macro_f1": 0.8,
 *   "sample_aggregates": [
 *     {"id": "...", "repetitions": 3, "passed": 2, "errored": 0, "pass_rate": 0.667,
 *      "pass_rate_ci": {"low": 0.208, "high": 0.939, "confidence": 0.95}, "unstable": true,
 *      "score_mean": 0.72, "score_stddev": 0.31, "metrics": {"exact-match": {"mean": 0.667, "stddev": 0.471, "min": 0.0, "max": 1.0, "observations": 3}}}
 *   ],
 *   "samples": [
 *     {"id": "...", "repetition": 0, "tags": ["geography"], "adversarial": null, "actual_output": "...", "scores": {"exact-match": {"score": 1.0, "details": {...}}}}
 *   ],
 *   "failures": [
 *     {"sample_id": "...", "metric": "...", "error": "..."}
 *   ]
 * }
 *
 * Returns an associative array; callers that want bytes call
 * `json_encode(... JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)`.
 */
final class JsonReportRenderer
{
    /**
     * @return array<string, mixed>
     */
    public function render(EvalReport $report): array
    {
        $metrics = [];
        foreach ($report->metricNames() as $name) {
            $metrics[$name] = $report->metricAggregate($name);
        }

        $samples = [];
        foreach ($report->sampleResults as $result) {
            $scores = [];
            foreach ($result->metricScores as $metricName => $score) {
                $scores[$metricName] = [
                    'score' => $score->score,
                    'details' => $score->details,
                ];
            }
            $samples[] = [
                'id' => $result->sample->id,
                'repetition' => $result->repetition,
                'tags' => $report->tagsForSample($result->sample),
                'adversarial' => $report->adversarialForSample($result->sample),
                'actual_output' => $result->actualOutput,
                'scores' => $scores,
            ];
        }

        $failures = [];
        foreach ($report->failures as $failure) {
            $failures[] = [
                'sample_id' => $failure->sampleId,
                'metric' => $failure->metricName,
                'error' => $failure->error,
                'repetition' => $failure->repetition,
            ];
        }

        $sampleAggregates = [];
        foreach ($report->sampleAggregates() as $aggregate) {
            $sampleAggregates[] = $aggregate->toArray();
        }

        return [
            'schema_version' => $report->schemaVersion,
            'dataset_schema_version' => $report->datasetSchemaVersion,
            'dataset' => $report->datasetName,
            'started_at' => $report->startedAt,
            'finished_at' => $report->finishedAt,
            'duration_seconds' => $report->durationSeconds(),
            'total_samples' => $report->totalSamples(),
            'total_executions' => $report->totalExecutions(),
            'repetitions' => $report->repetitions(),
            'total_failures' => $report->totalFailures(),
            'pass_rate' => round($report->runPassRate(), 6),
            'precision' => $report->precision(),
            'metrics' => $metrics,
            'metric_distributions' => $report->metricDistributions(),
            'usage' => $report->usageSummary(),
            'cohorts' => $report->cohortSummaries(),
            'adversarial' => $report->adversarialSummary(),
            'macro_f1' => $report->macroF1(),
            'sample_aggregates' => $sampleAggregates,
            'samples' => $samples,
            'failures' => $failures,
        ];
    }
}
