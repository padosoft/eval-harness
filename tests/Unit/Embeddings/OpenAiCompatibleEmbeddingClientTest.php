<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Embeddings;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\Contracts\EmbeddingClient;
use Padosoft\EvalHarness\Contracts\ProvidesUsageDetails;
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
        $this->assertInstanceOf(ProvidesUsageDetails::class, $client);
        $this->assertSame([], $client->usageDetails());
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
        $this->assertInstanceOf(ProvidesUsageDetails::class, $client);
        $this->assertSame([], $client->usageDetails());
        Http::assertNothingSent();
    }

    public function test_provider_usage_details_are_exposed_from_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [['embedding' => [1.0]]],
                'usage' => [
                    'prompt_tokens' => '3',
                    'completion_tokens' => 0,
                    'total_tokens' => 3,
                    'total_cost_usd' => '0.0015',
                    'latency_ms' => '18.25',
                ],
            ]),
        ]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);
        $this->assertInstanceOf(ProvidesUsageDetails::class, $client);

        $client->embedMany(['one']);
        $usage = $client->usageDetails();

        $this->assertSame(3, $usage['prompt_tokens']);
        $this->assertSame(0, $usage['completion_tokens']);
        $this->assertSame(3, $usage['total_tokens']);
        $this->assertSame(0.0015, $usage['cost_usd']);
        $this->assertSame(18.25, $usage['latency_ms']);
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

    public function test_http_failure_exception_does_not_include_response_body(): void
    {
        Http::fake(['*' => Http::response('secret prompt echo', 503)]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        try {
            $client->embedMany(['one']);
            $this->fail('Expected HTTP failure to throw.');
        } catch (MetricException $e) {
            $this->assertSame('Embeddings request failed: HTTP 503.', $e->getMessage());
            $this->assertStringNotContainsString('secret prompt echo', $e->getMessage());
        }
    }

    public function test_retryable_http_failures_are_retried(): void
    {
        config([
            'eval-harness.metrics.cosine_embedding.endpoint' => 'https://embeddings.test/v1',
            'eval-harness.runtime.provider_retry_attempts' => 1,
            'eval-harness.runtime.provider_retry_sleep_milliseconds' => 0,
        ]);

        Http::fake([
            'https://embeddings.test/v1' => Http::sequence()
                ->push('try again', 500)
                ->push(['data' => [['embedding' => [1, '2']]]], 200),
        ]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $this->assertSame([[1.0, 2.0]], $client->embedMany(['one']));
        Http::assertSentCount(2);
    }

    public function test_non_retryable_http_failures_are_not_retried(): void
    {
        config([
            'eval-harness.runtime.provider_retry_attempts' => 3,
            'eval-harness.runtime.provider_retry_sleep_milliseconds' => 0,
        ]);

        Http::fake(['*' => Http::response('bad request', 400)]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        try {
            $client->embedMany(['one']);
            $this->fail('Expected non-retryable HTTP failure to throw.');
        } catch (MetricException $e) {
            $this->assertStringContainsString('Embeddings request failed', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_digit_string_indexes_are_normalized_before_ordering(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['index' => '1', 'embedding' => [0, '2.5']],
                    ['index' => '0', 'embedding' => [1, 0]],
                ],
            ]),
        ]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $this->assertSame([[1.0, 0.0], [0.0, 2.5]], $client->embedMany(['first', 'second']));
    }

    public function test_zero_padded_digit_string_indexes_are_normalized_before_ordering(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['index' => '01', 'embedding' => [0, '2.5']],
                    ['index' => '00', 'embedding' => [1, 0]],
                ],
            ]),
        ]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $this->assertSame([[1.0, 0.0], [0.0, 2.5]], $client->embedMany(['first', 'second']));
    }

    public function test_invalid_response_index_throws_metric_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['index' => 'first', 'embedding' => [1, 0]],
                ],
            ]),
        ]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('index must be a non-negative integer or digit string');

        $client->embedMany(['first']);
    }

    public function test_partial_response_indexes_throw_metric_exception(): void
    {
        Http::fake([
            '*' => Http::response([
                'data' => [
                    ['index' => 0, 'embedding' => [1, 0]],
                    ['embedding' => [0, 1]],
                ],
            ]),
        ]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('indexes must be present on every data entry');

        $client->embedMany(['first', 'second']);
    }

    public function test_transport_errors_are_retried(): void
    {
        config([
            'eval-harness.runtime.provider_retry_attempts' => 1,
            'eval-harness.runtime.provider_retry_sleep_milliseconds' => 0,
        ]);

        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new ConnectionException('network down');
            }

            return Http::response(['data' => [['embedding' => [0.5]]]], 200);
        });

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $this->assertSame([[0.5]], $client->embedMany(['one']));
        $this->assertSame(2, $calls);
    }

    public function test_non_transport_exceptions_are_not_retried_or_wrapped(): void
    {
        config([
            'eval-harness.runtime.provider_retry_attempts' => 3,
            'eval-harness.runtime.provider_retry_sleep_milliseconds' => 0,
        ]);

        $calls = 0;
        Http::fake(function () use (&$calls): never {
            $calls++;

            throw new \InvalidArgumentException('bad request setup');
        });

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        try {
            $client->embedMany(['one']);
            $this->fail('Expected non-transport exception to bubble.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('bad request setup', $e->getMessage());
        }

        $this->assertSame(1, $calls);
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

    public function test_non_finite_embedding_component_throws_metric_exception(): void
    {
        Http::fake([
            '*' => Http::response(['data' => [['embedding' => ['1e309']]]]),
        ]);

        /** @var EmbeddingClient $client */
        $client = $this->app->make(EmbeddingClient::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('non-finite component');

        $client->embedMany(['one']);
    }
}
