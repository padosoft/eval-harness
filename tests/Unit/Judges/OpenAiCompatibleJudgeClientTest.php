<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Judges;

use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\Contracts\JudgeClient;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Tests\TestCase;

final class OpenAiCompatibleJudgeClientTest extends TestCase
{
    public function test_judge_sends_openai_compatible_json_request(): void
    {
        config([
            'eval-harness.metrics.llm_as_judge.endpoint' => 'https://judge.test/v1',
            'eval-harness.metrics.llm_as_judge.api_key' => 'judge-token',
            'eval-harness.metrics.llm_as_judge.model' => 'judge-test',
            'eval-harness.metrics.llm_as_judge.timeout_seconds' => 11,
        ]);

        Http::fake([
            'https://judge.test/v1' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"score": 1.0, "reason": "ok"}'],
                ]],
            ]),
        ]);

        /** @var JudgeClient $client */
        $client = $this->app->make(JudgeClient::class);

        $raw = $client->judge('grade this');

        $this->assertSame('{"score": 1.0, "reason": "ok"}', $raw);
        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $request->url() === 'https://judge.test/v1'
                && $request->hasHeader('Authorization', 'Bearer judge-token')
                && ($body['model'] ?? null) === 'judge-test'
                && ($body['temperature'] ?? null) === 0
                && ($body['seed'] ?? null) === 42
                && (($body['response_format']['type'] ?? null) === 'json_object')
                && (($body['messages'][0]['content'] ?? null) === 'grade this');
        });
    }

    public function test_http_failure_throws_metric_exception(): void
    {
        Http::fake(['*' => Http::response('boom', 429)]);

        /** @var JudgeClient $client */
        $client = $this->app->make(JudgeClient::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('LLM judge request failed');

        $client->judge('grade this');
    }

    public function test_retryable_http_failures_are_retried(): void
    {
        config([
            'eval-harness.metrics.llm_as_judge.endpoint' => 'https://judge.test/v1',
            'eval-harness.runtime.provider_retry_attempts' => 1,
            'eval-harness.runtime.provider_retry_sleep_milliseconds' => 0,
        ]);

        Http::fake([
            'https://judge.test/v1' => Http::sequence()
                ->push('rate limited', 429)
                ->push([
                    'choices' => [[
                        'message' => ['content' => '{"score": 1.0, "reason": "ok"}'],
                    ]],
                ], 200),
        ]);

        /** @var JudgeClient $client */
        $client = $this->app->make(JudgeClient::class);

        $this->assertSame('{"score": 1.0, "reason": "ok"}', $client->judge('grade this'));
        Http::assertSentCount(2);
    }

    public function test_non_retryable_http_failures_are_not_retried(): void
    {
        config([
            'eval-harness.runtime.provider_retry_attempts' => 3,
            'eval-harness.runtime.provider_retry_sleep_milliseconds' => 0,
        ]);

        Http::fake(['*' => Http::response('bad request', 400)]);

        /** @var JudgeClient $client */
        $client = $this->app->make(JudgeClient::class);

        try {
            $client->judge('grade this');
            $this->fail('Expected non-retryable HTTP failure to throw.');
        } catch (MetricException $e) {
            $this->assertStringContainsString('LLM judge request failed', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_missing_message_content_throws_metric_exception(): void
    {
        Http::fake(['*' => Http::response(['choices' => []])]);

        /** @var JudgeClient $client */
        $client = $this->app->make(JudgeClient::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('missing choices[0].message.content');

        $client->judge('grade this');
    }
}
