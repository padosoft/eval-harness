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
        $this->expectExceptionMessage('LLM-as-judge request failed');

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
