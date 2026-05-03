<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;

/**
 * Contract for every scoring metric.
 *
 * Implementations:
 *   - {@see ExactMatchMetric} — case-sensitive string equality.
 *   - {@see ContainsMetric} — case-sensitive substring containment.
 *   - {@see RegexMetric} — PHP regex pattern match.
 *   - {@see RougeLMetric} — offline ROUGE-L F1.
 *   - {@see CitationGroundednessMetric} — baseline citation presence.
 *   - {@see CosineEmbeddingMetric} — semantic similarity via
 *     embeddings.
 *   - {@see BertScoreLikeMetric} — token-level semantic overlap via
 *     the configured embedding client.
 *   - {@see LlmAsJudgeMetric} — strict-JSON LLM grading.
 *
 * Adding a new metric:
 *   1. Implement this interface.
 *   2. EITHER pass the FQCN directly to `withMetrics([...])` (the
 *      {@see MetricResolver} resolves it via the container so
 *      constructor deps are auto-wired), OR register a container
 *      alias in your service provider — e.g.
 *      `$this->app->bind('my-metric', MyMetric::class);` — and pass
 *      `'my-metric'` to `withMetrics([...])`.
 *
 * Note: the harness does NOT read a static `metrics.aliases` config
 * key. Built-in aliases are exposed through {@see MetricResolver::aliases()};
 * downstream extension goes through container bindings, not config.
 *
 * Per R23 (pluggable pipeline registry): every concrete class
 * resolved by {@see MetricResolver} is asserted to implement this
 * interface; mis-typed entries fail fast with a descriptive
 * container resolution error rather than a runtime "method does not
 * exist" downstream.
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
