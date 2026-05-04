<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Judges;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Padosoft\EvalHarness\Contracts\JudgeClient;
use Padosoft\EvalHarness\Contracts\ProvidesUsageDetails;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Support\ProviderHttpRetry;
use Padosoft\EvalHarness\Support\ProviderUsageDetails;
use Padosoft\EvalHarness\Support\TimeoutNormalizer;

/**
 * OpenAI-compatible chat completions transport for judge metrics.
 */
final class OpenAiCompatibleJudgeClient implements JudgeClient, ProvidesUsageDetails
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

    public function judge(string $prompt): string
    {
        $this->usageDetails = [];

        $endpoint = (string) $this->config->get(
            'eval-harness.metrics.llm_as_judge.endpoint',
            'https://api.openai.com/v1/chat/completions',
        );
        $apiKey = (string) $this->config->get(
            'eval-harness.metrics.llm_as_judge.api_key',
            '',
        );
        $model = (string) $this->config->get(
            'eval-harness.metrics.llm_as_judge.model',
            'gpt-4o-mini',
        );
        $timeout = TimeoutNormalizer::normalize(
            $this->config->get('eval-harness.metrics.llm_as_judge.timeout_seconds'),
            60,
        );

        $request = $this->http->timeout($timeout);
        if ($apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        $response = ProviderHttpRetry::post(
            request: $request,
            config: $this->config,
            endpoint: $endpoint,
            payload: [
                'model' => $model,
                'temperature' => 0,
                'seed' => 42,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ],
            operation: 'LLM judge',
        );

        if ($response->failed()) {
            throw new MetricException(
                sprintf('LLM judge request failed: HTTP %d.', $response->status()),
            );
        }

        /** @var array<mixed> $body */
        $body = (array) $response->json();
        $this->usageDetails = ProviderUsageDetails::fromResponseBody($body);

        $content = $body['choices'][0]['message']['content'] ?? null;

        if (! is_string($content) || $content === '') {
            throw new MetricException(
                'LLM judge response missing choices[0].message.content (or it is empty).',
            );
        }

        return $content;
    }
}
