<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Contracts\EmbeddingClient;
use Padosoft\EvalHarness\Contracts\ProvidesUsageDetails;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\BertScoreLikeMetric;
use Padosoft\EvalHarness\Tests\TestCase;

final class BertScoreLikeMetricTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $metric = new BertScoreLikeMetric(new FakeEmbeddingClient);

        $this->assertSame('bertscore-like', $metric->name());
    }

    public function test_semantically_matching_tokens_score_one(): void
    {
        $client = new FakeEmbeddingClient([
            'cat' => [1.0, 0.0, 0.0],
            'feline' => [1.0, 0.0, 0.0],
            'sat' => [0.0, 1.0, 0.0],
        ]);
        $metric = new BertScoreLikeMetric($client);

        $score = $metric->score(
            new DatasetSample(id: 'a', input: [], expectedOutput: 'cat sat'),
            'feline sat',
        );

        $this->assertSame(1.0, $score->score);
        $this->assertSame(['cat', 'sat', 'feline', 'sat'], $client->requests[0]);
        $this->assertSame(2, $score->details['expected_tokens']);
        $this->assertSame(2, $score->details['actual_tokens']);
    }

    public function test_provider_usage_details_are_attached_to_metric_score(): void
    {
        $metric = new BertScoreLikeMetric(new UsageEmbeddingClient);

        $score = $metric->score(
            new DatasetSample(id: 'a', input: [], expectedOutput: 'cat'),
            'cat',
        );

        $this->assertSame(6, $score->details['usage']['prompt_tokens']);
        $this->assertSame(6, $score->details['usage']['total_tokens']);
        $this->assertSame(12.5, $score->details['usage']['latency_ms']);
    }

    public function test_partial_token_overlap_scores_precision_recall_f1(): void
    {
        $metric = new BertScoreLikeMetric(new FakeEmbeddingClient([
            'cat' => [1.0, 0.0, 0.0],
            'dog' => [0.0, 1.0, 0.0],
            'sat' => [0.0, 0.0, 1.0],
        ]));

        $score = $metric->score(
            new DatasetSample(id: 'a', input: [], expectedOutput: 'cat sat'),
            'dog sat',
        );

        $this->assertEqualsWithDelta(0.5, $score->score, 1e-6);
        $this->assertEqualsWithDelta(0.5, $score->details['precision'], 1e-6);
        $this->assertEqualsWithDelta(0.5, $score->details['recall'], 1e-6);
    }

    public function test_orthogonal_single_tokens_score_zero(): void
    {
        $metric = new BertScoreLikeMetric(new FakeEmbeddingClient([
            'cat' => [1.0, 0.0],
            'dog' => [0.0, 1.0],
        ]));

        $score = $metric->score(
            new DatasetSample(id: 'a', input: [], expectedOutput: 'cat'),
            'dog',
        );

        $this->assertSame(0.0, $score->score);
    }

    public function test_empty_expected_and_actual_score_one(): void
    {
        $metric = new BertScoreLikeMetric(new FakeEmbeddingClient);

        $score = $metric->score(
            new DatasetSample(id: 'a', input: [], expectedOutput: '...'),
            '!!!',
        );

        $this->assertSame(1.0, $score->score);
    }

    public function test_empty_one_sided_tokens_score_zero(): void
    {
        $metric = new BertScoreLikeMetric(new FakeEmbeddingClient([
            'dog' => [1.0],
        ]));

        $score = $metric->score(
            new DatasetSample(id: 'a', input: [], expectedOutput: '...'),
            'dog',
        );

        $this->assertSame(0.0, $score->score);
    }

    public function test_non_string_expected_output_throws(): void
    {
        $metric = new BertScoreLikeMetric(new FakeEmbeddingClient);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('must be a string for bertscore-like metric');

        $metric->score(new DatasetSample(id: 'a', input: [], expectedOutput: ['nope']), 'dog');
    }

    public function test_invalid_utf8_throws(): void
    {
        $metric = new BertScoreLikeMetric(new FakeEmbeddingClient);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('must be valid UTF-8');

        $metric->score(new DatasetSample(id: 'a', input: [], expectedOutput: 'valid'), "\xB1\x31");
    }

    public function test_token_limit_throws_before_embedding_provider_call(): void
    {
        $client = new FakeEmbeddingClient;
        $metric = new BertScoreLikeMetric($client, maxTokens: 1);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('supports at most 1 tokens per field');

        try {
            $metric->score(new DatasetSample(id: 'a', input: [], expectedOutput: 'one two'), 'one');
        } finally {
            $this->assertSame([], $client->requests);
        }
    }

    public function test_dimension_mismatch_throws(): void
    {
        $metric = new BertScoreLikeMetric(new FakeEmbeddingClient([
            'cat' => [1.0],
            'dog' => [1.0, 0.0],
        ]));

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('different dimensionality');

        $metric->score(new DatasetSample(id: 'a', input: [], expectedOutput: 'cat'), 'dog');
    }
}

final class FakeEmbeddingClient implements EmbeddingClient
{
    /**
     * @var array<string, list<float>>
     */
    private array $vectorsByText;

    /**
     * @var list<list<string>>
     */
    public array $requests = [];

    /**
     * @param  array<string, list<float>>  $vectorsByText
     */
    public function __construct(array $vectorsByText = [])
    {
        $this->vectorsByText = $vectorsByText;
    }

    public function embedMany(array $texts): array
    {
        $this->requests[] = $texts;

        return array_map(
            fn (string $text): array => $this->vectorsByText[$text] ?? [1.0],
            $texts,
        );
    }
}

final class UsageEmbeddingClient implements EmbeddingClient, ProvidesUsageDetails
{
    public function embedMany(array $texts): array
    {
        return array_map(static fn (string $text): array => [1.0], $texts);
    }

    public function usageDetails(): array
    {
        return [
            'prompt_tokens' => 6,
            'total_tokens' => 6,
            'latency_ms' => 12.5,
        ];
    }
}
