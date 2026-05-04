<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\CosineEmbeddingMetric;
use Padosoft\EvalHarness\Tests\TestCase;

final class CosineEmbeddingMetricTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        /** @var CosineEmbeddingMetric $metric */
        $metric = $this->app->make(CosineEmbeddingMetric::class);
        $this->assertSame('cosine-embedding', $metric->name());
    }

    public function test_identical_vectors_score_one(): void
    {
        // Both calls return the same vector → cosine similarity = 1.
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['embedding' => [1.0, 0.0, 0.0]],
                    ['embedding' => [1.0, 0.0, 0.0]],
                ],
            ]),
        ]);

        /** @var CosineEmbeddingMetric $metric */
        $metric = $this->app->make(CosineEmbeddingMetric::class);
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'expected text');

        $score = $metric->score($sample, 'actual text');

        $this->assertEqualsWithDelta(1.0, $score->score, 1e-6);
        $this->assertSame(3, $score->details['expected_dim']);
        $this->assertSame(3, $score->details['actual_dim']);
    }

    public function test_provider_usage_details_are_attached_to_metric_score(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['embedding' => [1.0]],
                    ['embedding' => [1.0]],
                ],
                'usage' => [
                    'prompt_tokens' => 4,
                    'total_tokens' => 4,
                    'cost_usd' => '0.0004',
                    'latency_ms' => '12.75',
                ],
            ]),
        ]);

        /** @var CosineEmbeddingMetric $metric */
        $metric = $this->app->make(CosineEmbeddingMetric::class);
        $score = $metric->score(
            new DatasetSample(id: 'a', input: [], expectedOutput: 'expected'),
            'actual',
        );

        $this->assertSame(4, $score->details['usage']['prompt_tokens']);
        $this->assertSame(4, $score->details['usage']['total_tokens']);
        $this->assertSame(0.0004, $score->details['usage']['cost_usd']);
        $this->assertSame(12.75, $score->details['usage']['latency_ms']);
    }

    public function test_orthogonal_vectors_score_zero(): void
    {
        // First embedded text returns [1,0,0], second [0,1,0].
        // Cosine similarity is exactly the lower bound and clamps to 0.0.
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['embedding' => [1.0, 0.0, 0.0]],
                    ['embedding' => [0.0, 1.0, 0.0]],
                ],
            ]),
        ]);

        /** @var CosineEmbeddingMetric $metric */
        $metric = $this->app->make(CosineEmbeddingMetric::class);
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'foo');

        $score = $metric->score($sample, 'bar');

        $this->assertEqualsWithDelta(0.0, $score->score, 1e-6);
    }

    public function test_partial_overlap_scores_in_between(): void
    {
        // Cosine([1,1,0], [1,0,0]) = 1 / sqrt(2) ≈ 0.7071.
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['embedding' => [1.0, 1.0, 0.0]],
                    ['embedding' => [1.0, 0.0, 0.0]],
                ],
            ]),
        ]);

        /** @var CosineEmbeddingMetric $metric */
        $metric = $this->app->make(CosineEmbeddingMetric::class);
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'foo');

        $score = $metric->score($sample, 'bar');

        $this->assertEqualsWithDelta(1.0 / sqrt(2.0), $score->score, 1e-6);
    }

    public function test_zero_vector_returns_zero_not_nan(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['embedding' => [0.0, 0.0, 0.0]],
                    ['embedding' => [1.0, 0.0, 0.0]],
                ],
            ]),
        ]);

        /** @var CosineEmbeddingMetric $metric */
        $metric = $this->app->make(CosineEmbeddingMetric::class);
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'foo');

        $score = $metric->score($sample, 'bar');

        $this->assertSame(0.0, $score->score);
    }

    public function test_http_failure_throws_metric_exception(): void
    {
        Http::fake([
            '*' => Http::response('boom', 500),
        ]);

        /** @var CosineEmbeddingMetric $metric */
        $metric = $this->app->make(CosineEmbeddingMetric::class);
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'foo');

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('Embeddings request failed');

        $metric->score($sample, 'bar');
    }

    public function test_malformed_response_throws(): void
    {
        Http::fake([
            '*' => Http::response(['data' => []]),
        ]);

        /** @var CosineEmbeddingMetric $metric */
        $metric = $this->app->make(CosineEmbeddingMetric::class);
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'foo');

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('returned 0 vector(s)');

        $metric->score($sample, 'bar');
    }

    public function test_dimensionality_mismatch_throws(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['embedding' => [1.0, 0.0]],
                    ['embedding' => [1.0, 0.0, 0.0]],
                ],
            ]),
        ]);

        /** @var CosineEmbeddingMetric $metric */
        $metric = $this->app->make(CosineEmbeddingMetric::class);
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 'foo');

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('different dimensionality');

        $metric->score($sample, 'bar');
    }

    public function test_non_string_expected_throws(): void
    {
        /** @var CosineEmbeddingMetric $metric */
        $metric = $this->app->make(CosineEmbeddingMetric::class);
        $sample = new DatasetSample(id: 'a', input: [], expectedOutput: 12345);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('must be a string for cosine-embedding');

        $metric->score($sample, 'bar');
    }
}
