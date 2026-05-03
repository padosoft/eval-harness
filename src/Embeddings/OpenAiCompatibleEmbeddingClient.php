<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Embeddings;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Padosoft\EvalHarness\Contracts\EmbeddingClient;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Support\TimeoutNormalizer;

/**
 * OpenAI-compatible embeddings transport used by built-in metrics.
 */
final class OpenAiCompatibleEmbeddingClient implements EmbeddingClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
    ) {}

    public function embedMany(array $texts): array
    {
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

        $response = $request->post($endpoint, [
            'model' => $model,
            'input' => $texts,
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

        $hasIndexes = true;
        foreach ($data as $entry) {
            if (! is_array($entry) || ! is_int($entry['index'] ?? null)) {
                $hasIndexes = false;
                break;
            }
        }

        if ($hasIndexes) {
            usort(
                $data,
                static fn (array $left, array $right): int => $left['index'] <=> $right['index'],
            );

            foreach ($data as $position => $entry) {
                if (($entry['index'] ?? null) !== $position) {
                    throw new MetricException(
                        sprintf('Embeddings response index order is incomplete at position %d.', $position),
                    );
                }
            }
        }

        return array_values($data);
    }

    /**
     * @param  array<mixed>  $vector
     * @return list<float>
     */
    private function normalizeVector(array $vector, int $entryIndex): array
    {
        $normalised = [];
        foreach ($vector as $componentIndex => $component) {
            if (! is_numeric($component)) {
                throw new MetricException(
                    sprintf(
                        'Embedding vector data[%d].embedding contains non-numeric component at index %d.',
                        $entryIndex,
                        $componentIndex,
                    ),
                );
            }

            $normalised[] = (float) $component;
        }

        return $normalised;
    }
}
