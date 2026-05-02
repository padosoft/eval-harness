<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Semantic-similarity metric: embed expected + actual via an
 * embeddings provider, return `1 - cosine_distance` clamped to
 * [0, 1].
 *
 * Transport: raw `Http::` against the configured provider (defaults
 * to OpenAI's embeddings endpoint; OpenRouter / Regolo / any
 * OpenAI-compatible embeddings endpoint works with only an env-var
 * change). Tests substitute via `Http::fake()` for deterministic
 * offline runs — see
 * tests/Unit/Metrics/CosineEmbeddingMetricTest.php for canned
 * vectors.
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
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
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

        $expectedVec = $this->embed($sample->expectedOutput);
        $actualVec = $this->embed($actualOutput);

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
     * @return list<float>
     */
    private function embed(string $text): array
    {
        $endpoint = (string) $this->config->get(
            'eval-harness.metrics.cosine_embedding.endpoint',
            'https://api.openai.com/v1/embeddings',
        );
        $apiKey = (string) $this->config->get(
            'eval-harness.metrics.cosine_embedding.api_key',
            '',
        );
        $model = (string) $this->config->get(
            'eval-harness.metrics.cosine_embedding.model',
            'text-embedding-3-small',
        );
        $timeout = (int) $this->config->get(
            'eval-harness.metrics.cosine_embedding.timeout_seconds',
            30,
        );

        $request = $this->http->timeout($timeout);
        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        $response = $request->post($endpoint, [
            'model' => $model,
            'input' => $text,
        ]);

        if ($response->failed()) {
            throw new MetricException(
                sprintf(
                    'Embeddings request failed: HTTP %d (%s).',
                    $response->status(),
                    substr((string) $response->body(), 0, 200),
                ),
            );
        }

        /** @var array<mixed> $body */
        $body = (array) $response->json();
        $vector = $body['data'][0]['embedding'] ?? null;

        if (! is_array($vector) || $vector === []) {
            throw new MetricException(
                'Embeddings response is missing data[0].embedding or it is not a non-empty array.',
            );
        }

        $normalised = [];
        foreach ($vector as $i => $component) {
            if (! is_numeric($component)) {
                throw new MetricException(
                    sprintf('Embedding vector contains non-numeric component at index %d.', $i),
                );
            }
            $normalised[] = (float) $component;
        }

        return $normalised;
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
