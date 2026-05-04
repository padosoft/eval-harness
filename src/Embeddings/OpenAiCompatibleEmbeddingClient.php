<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Embeddings;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Padosoft\EvalHarness\Contracts\EmbeddingClient;
use Padosoft\EvalHarness\Contracts\ProvidesUsageDetails;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Support\ProviderHttpRetry;
use Padosoft\EvalHarness\Support\ProviderUsageDetails;
use Padosoft\EvalHarness\Support\TimeoutNormalizer;

/**
 * OpenAI-compatible embeddings transport used by built-in metrics.
 */
final class OpenAiCompatibleEmbeddingClient implements EmbeddingClient, ProvidesUsageDetails
{
    /**
     * @var array<string, int|float>
     */
    private array $usageDetails = [];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
    ) {}

    public function usageDetails(): array
    {
        return $this->usageDetails;
    }

    public function embedMany(array $texts): array
    {
        $this->usageDetails = [];

        if ($texts === []) {
            return [];
        }

        foreach ($texts as $index => $text) {
            if (! is_string($text)) {
                throw new MetricException(
                    sprintf('Embedding input at index %d must be a string; got %s.', $index, get_debug_type($text)),
                );
            }
        }

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
        $timeout = TimeoutNormalizer::normalize(
            $this->config->get('eval-harness.metrics.cosine_embedding.timeout_seconds'),
            30,
        );

        $request = $this->http->timeout($timeout);
        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        $startedAt = microtime(true);
        $response = ProviderHttpRetry::post(
            request: $request,
            config: $this->config,
            endpoint: $endpoint,
            payload: [
                'model' => $model,
                'input' => $texts,
            ],
            operation: 'Embeddings',
        );
        $latencyMs = (microtime(true) - $startedAt) * 1000.0;

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
        $this->usageDetails = ProviderUsageDetails::fromResponseBody($body, $latencyMs);

        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            throw new MetricException('Embeddings response is missing data or it is not an array.');
        }

        $ordered = $this->orderDataEntries($data, count($texts));

        $vectors = [];
        foreach ($ordered as $index => $entry) {
            $vector = is_array($entry) ? ($entry['embedding'] ?? null) : null;

            if (! is_array($vector) || $vector === []) {
                throw new MetricException(
                    sprintf('Embeddings response is missing data[%d].embedding or it is not a non-empty array.', $index),
                );
            }

            $vectors[] = $this->normalizeVector($vector, $index);
        }

        return $vectors;
    }

    /**
     * @param  array<mixed>  $data
     * @return list<array<mixed>>
     */
    private function orderDataEntries(array $data, int $expectedCount): array
    {
        if (count($data) !== $expectedCount) {
            throw new MetricException(
                sprintf(
                    'Embeddings response returned %d vector(s); expected %d.',
                    count($data),
                    $expectedCount,
                ),
            );
        }

        $hasIndexes = false;
        foreach ($data as $entry) {
            if (is_array($entry) && array_key_exists('index', $entry)) {
                $hasIndexes = true;
                break;
            }
        }

        if ($hasIndexes) {
            foreach ($data as $position => &$entry) {
                if (! is_array($entry) || ! array_key_exists('index', $entry)) {
                    throw new MetricException(
                        'Embeddings response indexes must be present on every data entry when any index is present.',
                    );
                }

                $index = $this->normalizeResponseIndex($entry['index']);
                if ($index === null) {
                    throw new MetricException(
                        sprintf('Embeddings response data[%d].index must be a non-negative integer or digit string.', $position),
                    );
                }

                $entry['index'] = $index;
            }
            unset($entry);

            /** @var list<array<mixed>> $indexedData */
            $indexedData = array_values($data);
            usort(
                $indexedData,
                static fn (array $left, array $right): int => $left['index'] <=> $right['index'],
            );

            foreach ($indexedData as $position => $entry) {
                if (($entry['index'] ?? null) !== $position) {
                    throw new MetricException(
                        sprintf('Embeddings response index order is incomplete at position %d.', $position),
                    );
                }
            }

            return $indexedData;
        }

        return array_values($data);
    }

    private function normalizeResponseIndex(mixed $index): ?int
    {
        if (is_int($index)) {
            return $index >= 0 ? $index : null;
        }

        if (! is_string($index) || ! ctype_digit($index)) {
            return null;
        }

        $filtered = filter_var(
            $index,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );

        return is_int($filtered) ? $filtered : null;
    }

    /**
     * @param  array<mixed>  $vector
     * @return list<float>
     */
    private function normalizeVector(array $vector, int $entryIndex): array
    {
        $normalised = [];
        foreach ($vector as $componentIndex => $component) {
            $value = $this->normalizeVectorComponent($component);
            if ($value === null) {
                throw new MetricException(
                    sprintf(
                        'Embedding vector data[%d].embedding contains non-numeric component or non-finite component at index %d.',
                        $entryIndex,
                        $componentIndex,
                    ),
                );
            }

            $normalised[] = $value;
        }

        return $normalised;
    }

    private function normalizeVectorComponent(mixed $component): ?float
    {
        if (! is_int($component) && ! is_float($component) && ! is_string($component)) {
            return null;
        }

        if (! is_numeric($component)) {
            return null;
        }

        $value = (float) $component;

        return ! is_nan($value) && ! is_infinite($value) ? $value : null;
    }
}
