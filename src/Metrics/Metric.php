<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;

/**
 * Contract for every scoring metric.
 *
 * Implementations:
 *   - {@see ExactMatchMetric} — case-sensitive string equality.
 *   - {@see CosineEmbeddingMetric} — semantic similarity via
 *     embeddings (transport: raw Http:: against any OpenAI-compatible
 *     embeddings endpoint).
 *   - {@see LlmAsJudgeMetric} — strict-JSON LLM grading.
 *
 * Adding a new metric: implement this interface, register the
 * concrete class in `config/eval-harness.php` under `metrics.aliases`,
 * and the {@see MetricResolver} will pick it up automatically.
 *
 * Per R23 (pluggable pipeline registry): every Metric registered
 * via the resolver is FQCN-validated at boot to confirm it really
 * implements this interface; mis-typed entries fail fast with a
 * descriptive container resolution error.
 */
interface Metric
{
    /**
     * Stable identifier surfaced in reports (e.g. "exact-match").
     * Lower-kebab-case by convention.
     */
    public function name(): string;

    public function score(DatasetSample $sample, string $actualOutput): MetricScore;
}
