<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Embeddings;

use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\Contracts\EmbeddingClient;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Tests\TestCase;

final class OpenAiCompatibleEmbeddingClientTest extends TestCase
{
    public function test_embed_many_sends_openai_compatible_batch_request(): void
    {
        config([
            'eval-harness.metrics.cosine_embedding.endpoint' => 'https://embeddings.test/v1',
            'eval-harness.metrics.cosine_embedding.api_key' => 'secret-token',
            'eval-harness.metrics.cosine_embedding.model' => 'embedding-test',
            'eval-harness.metrics.cosine_embedding.timeout_seconds' => 12,
        ]);

        Http::fake([
            'https://embeddings.test/v1' => Http::response([
                'data' => [
                    ['index' => 1, 'embedding' => [0, '2.5']],
                    ['index' => 0, 'embedding' => [1, 0]],
                ],
            ]),
        ]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $vectors = $client->embedMany(['first', 'second']);

        $this->assertSame([[1.0, 0.0], [0.0, 2.5]], $vectors);
        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://embeddings.test/v1'
                && $request->hasHeader('Authorization', 'Bearer secret-token')
                && ($body['model'] ?? null) === 'embedding-test'
                && ($body['input'] ?? null) === ['first', 'second'];
        });
    }

    public function test_empty_embedding_list_returns_empty_without_http_call(): void
    {
        Http::fake();

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $this->assertSame([], $client->embedMany([]));
        Http::assertNothingSent();
    }

    public function test_http_failure_throws_metric_exception(): void
    {
        Http::fake(['*' => Http::response('boom', 503)]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('Embeddings request failed');

        $client->embedMany(['one']);
    }

    public function test_malformed_vector_count_throws_metric_exception(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [['embedding' => [1.0, 0.0]]]]),
        ]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('returned 1 vector(s); expected 2');

        $client->embedMany(['one', 'two']);
    }

    public function test_non_numeric_embedding_component_throws_metric_exception(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [['embedding' => [1.0, 'nope']]]]),
        ]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('contains non-numeric component');

        $client->embedMany(['one']);
    }
}
