<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Contracts\EmbeddingClient;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Semantic-similarity metric: embed expected + actual via an
 * embeddings provider, return `1 - cosine_distance` clamped to
 * [0, 1].
 *
 * Transport is delegated to {@see EmbeddingClient}. The package binds
 * an OpenAI-compatible HTTP client by default, while tests and host
 * apps can bind deterministic fakes or Laravel AI-backed clients.
 *
 * Config keys (all under `eval-harness.metrics.cosine_embedding.*`):
 *   - endpoint: full URL of the embeddings POST endpoint.
 *   - api_key: bearer token (read from env at boot, never logged).
 *   - model: model identifier passed in the body.
 *   - timeout_seconds: per-request HTTP timeout.
 */
final class CosineEmbeddingMetric implements Metric
{
    public function __construct(
        private readonly EmbeddingClient $embeddings,
    ) {}

    public function name(): string
    {
        return 'cosine-embedding';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        if (! is_string($sample->expectedOutput)) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' expected_output must be a string for cosine-embedding metric; got %s.",
                    $sample->id,
                    get_debug_type($sample->expectedOutput),
                ),
            );
        }

        $vectors = $this->embeddings->embedMany([$sample->expectedOutput, $actualOutput]);
        if (count($vectors) !== 2) {
            throw new MetricException(
                sprintf('Cosine embedding client returned %d vector(s); expected 2.', count($vectors)),
            );
        }

        [$expectedVec, $actualVec] = $vectors;

        $similarity = $this->cosineSimilarity($expectedVec, $actualVec);
        // Clamp into [0, 1] — float math can produce 1.0000000000002.
        $clamped = max(0.0, min(1.0, $similarity));

        return new MetricScore(
            score: $clamped,
            details: [
                'expected_dim' => count($expectedVec),
                'actual_dim' => count($actualVec),
                'cosine_similarity' => $similarity,
                'clamped_score' => $clamped,
            ],
        );
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            throw new MetricException(
                sprintf(
                    'Cannot compute cosine similarity on vectors of different dimensionality (%d vs %d).',
                    count($a),
                    count($b),
                ),
            );
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $ai) {
            $bi = $b[$i];
            $dot += $ai * $bi;
            $normA += $ai * $ai;
            $normB += $bi * $bi;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            // Zero vector — undefined cosine. Return 0 so the run
            // surfaces the degenerate case instead of NaN-poisoning
            // the aggregation.
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
