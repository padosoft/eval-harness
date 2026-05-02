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
 *   "total_failures": 0,
 *   "metrics": {
 *     "exact-match": {"mean": 0.8, "p50": 1.0, "p95": 1.0, "pass_rate": 0.8}
 *   },
 *   "metric_distributions": {
 *     "exact-match": [{"min": 0.0, "max": 0.1, "count": 2}]
 *   },
 *   "cohorts": [
 *     {"name": "geography", "label": "geography", "is_untagged": false, "sample_count": 4, "metrics": {...}},
 *     {"name": null, "label": "(untagged)", "is_untagged": true, "sample_count": 1, "metrics": {...}}
 *   ],
 *   "macro_f1": 0.8,
 *   "samples": [
 *     {"id": "...", "tags": ["geography"], "actual_output": "...", "scores": {"exact-match": {"score": 1.0, "details": {...}}}}
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
                'tags' => $report->tagsForSample($result->sample),
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
            ];
        }

        return [
            'schema_version' => $report->schemaVersion,
            'dataset_schema_version' => $report->datasetSchemaVersion,
            'dataset' => $report->datasetName,
            'started_at' => $report->startedAt,
            'finished_at' => $report->finishedAt,
            'duration_seconds' => $report->durationSeconds(),
            'total_samples' => $report->totalSamples(),
            'total_failures' => $report->totalFailures(),
            'metrics' => $metrics,
            'metric_distributions' => $report->metricDistributions(),
            'cohorts' => $report->cohortSummaries(),
            'macro_f1' => $report->macroF1(),
            'samples' => $samples,
            'failures' => $failures,
        ];
    }
}
