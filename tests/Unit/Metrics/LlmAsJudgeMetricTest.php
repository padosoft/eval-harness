<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\LlmAsJudgeMetric;
use Padosoft\EvalHarness\Tests\TestCase;

final class LlmAsJudgeMetricTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $this->assertSame('llm-as-judge', $metric->name());
    }

    public function test_strict_json_response_is_scored(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"score": 0.85, "reason": "Mostly correct, minor wording diff."}',
                    ],
                ]],
            ]),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $sample = new DatasetSample(
            id: 'a',
            input: ['question' => 'What is 2+2?'],
            expectedOutput: '4',
        );

        $score = $metric->score($sample, 'four');

        $this->assertEqualsWithDelta(0.85, $score->score, 1e-9);
        $this->assertSame('Mostly correct, minor wording diff.', $score->details['judge_reason']);
    }

    public function test_shape_agnostic_input_is_encoded_when_question_key_is_missing(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"score": 1.0, "reason": "ok"}',
                    ],
                ]],
            ]),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $metric->score(new DatasetSample(id: 'a', input: ['q' => 'What is 2+2?'], expectedOutput: '4'), '4');

        Http::assertSent(static function ($request): bool {
            $prompt = $request->data()['messages'][0]['content'] ?? '';

            return is_string($prompt) && str_contains($prompt, '"q":"What is 2+2?"');
        });
    }

    public function test_shape_agnostic_input_encoding_failures_throw_before_judge_call(): void
    {
        Http::fake();

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('input must be JSON-encodable for llm-as-judge prompt fallback');

        try {
            $metric->score(new DatasetSample(id: 'a', input: ['q' => "\xB1\x31"], expectedOutput: 'e'), 'a');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_non_string_expected_encoding_failures_throw_before_judge_call(): void
    {
        Http::fake();

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('expected_output must be JSON-encodable for llm-as-judge');

        try {
            $metric->score(
                new DatasetSample(id: 'a', input: ['question' => 'q'], expectedOutput: ['bad' => "\xB1\x31"]),
                'a',
            );
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_usage_details_are_available_after_strict_response_failure(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"reason": "missing score"}'],
                ]],
                'usage' => [
                    'prompt_tokens' => 4,
                    'completion_tokens' => 1,
                    'total_tokens' => 5,
                ],
            ]),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);

        try {
            $metric->score(new DatasetSample(id: 'a', input: ['question' => 'q'], expectedOutput: 'e'), 'a');
            $this->fail('Expected strict judge response failure.');
        } catch (MetricException) {
            $this->assertSame(4, $metric->usageDetails()['prompt_tokens']);
            $this->assertSame(5, $metric->usageDetails()['total_tokens']);
        }
    }

    public function test_provider_usage_details_are_attached_to_metric_score(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '{"score": 1.0, "reason": "ok"}',
                    ],
                ]],
                'usage' => [
                    'prompt_tokens' => 7,
                    'completion_tokens' => 2,
                    'total_tokens' => 9,
                    'cost_usd' => '0.001',
                    'latency_ms' => '31.5',
                ],
            ]),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $score = $metric->score(new DatasetSample(id: 'a', input: ['question' => 'q'], expectedOutput: 'e'), 'a');

        $this->assertSame(7, $score->details['usage']['prompt_tokens']);
        $this->assertSame(2, $score->details['usage']['completion_tokens']);
        $this->assertSame(9, $score->details['usage']['total_tokens']);
        $this->assertSame(0.001, $score->details['usage']['cost_usd']);
        $this->assertSame(31.5, $score->details['usage']['latency_ms']);
    }

    public function test_response_with_code_fence_is_unwrapped(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "```json\n{\"score\": 0.5, \"reason\": \"meh\"}\n```",
                    ],
                ]],
            ]),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $sample = new DatasetSample(id: 'a', input: ['question' => 'q'], expectedOutput: 'e');

        $score = $metric->score($sample, 'a');

        $this->assertSame(0.5, $score->score);
    }

    public function test_malformed_json_throws(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'not json at all'],
                ]],
            ]),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $sample = new DatasetSample(id: 'a', input: ['question' => 'q'], expectedOutput: 'e');

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('not valid JSON');

        $metric->score($sample, 'a');
    }

    public function test_malformed_json_error_does_not_include_raw_judge_response(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => 'not json with secret prompt echo'],
                ]],
            ]),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);

        try {
            $metric->score(new DatasetSample(id: 'a', input: ['question' => 'q'], expectedOutput: 'e'), 'a');
            $this->fail('Expected malformed judge response to throw.');
        } catch (MetricException $e) {
            $this->assertStringContainsString('judge response is not valid JSON', $e->getMessage());
            $this->assertStringNotContainsString('secret prompt echo', $e->getMessage());
        }
    }

    public function test_missing_score_key_throws(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"reason": "no score"}'],
                ]],
            ]),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $sample = new DatasetSample(id: 'a', input: ['question' => 'q'], expectedOutput: 'e');

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage("missing required 'score' key");

        $metric->score($sample, 'a');
    }

    public function test_out_of_range_score_throws(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"score": 1.5, "reason": "x"}'],
                ]],
            ]),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $sample = new DatasetSample(id: 'a', input: ['question' => 'q'], expectedOutput: 'e');

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('out-of-range score');

        $metric->score($sample, 'a');
    }

    public function test_non_numeric_score_throws(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"score": "high", "reason": "x"}'],
                ]],
            ]),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $sample = new DatasetSample(id: 'a', input: ['question' => 'q'], expectedOutput: 'e');

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage("'score' must be numeric");

        $metric->score($sample, 'a');
    }

    public function test_http_failure_throws(): void
    {
        Http::fake([
            '*' => Http::response('rate limited', 429),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $sample = new DatasetSample(id: 'a', input: ['question' => 'q'], expectedOutput: 'e');

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('LLM judge request failed');

        $metric->score($sample, 'a');
    }

    public function test_request_uses_temperature_zero_and_seed(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"score": 1.0, "reason": "ok"}'],
                ]],
            ]),
        ]);

        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $sample = new DatasetSample(id: 'a', input: ['question' => 'q'], expectedOutput: 'e');

        $metric->score($sample, 'a');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['temperature'] ?? null) === 0
                && ($body['seed'] ?? null) === 42
                && (($body['response_format']['type'] ?? null) === 'json_object');
        });
    }
}
