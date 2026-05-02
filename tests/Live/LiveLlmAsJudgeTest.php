<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Live;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Metrics\LlmAsJudgeMetric;
use Padosoft\EvalHarness\Tests\TestCase;

/**
 * Opt-in live test for the LLM-as-judge metric.
 *
 * Requires `EVAL_HARNESS_LIVE_API_KEY` (and optionally
 * `EVAL_HARNESS_JUDGE_ENDPOINT` / `EVAL_HARNESS_JUDGE_MODEL`) to be
 * exported. CI does NOT run this suite — invoke explicitly:
 *
 *   EVAL_HARNESS_LIVE_API_KEY=sk-... vendor/bin/phpunit --testsuite Live
 *
 * Per `feedback_package_live_testsuite_opt_in`: every Padosoft
 * package with an external API ships an opt-in live testsuite that
 * markTestSkipped's on missing env var, so a contributor running the
 * default `phpunit` invocation never trips the live path
 * accidentally.
 */
final class LiveLlmAsJudgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $apiKey = getenv('EVAL_HARNESS_LIVE_API_KEY');
        if (! is_string($apiKey) || $apiKey === '') {
            $this->markTestSkipped(
                'Live test skipped — set EVAL_HARNESS_LIVE_API_KEY to run.',
            );
        }

        config()->set('eval-harness.metrics.llm_as_judge.api_key', $apiKey);
        $endpoint = getenv('EVAL_HARNESS_JUDGE_ENDPOINT');
        if (is_string($endpoint) && $endpoint !== '') {
            config()->set('eval-harness.metrics.llm_as_judge.endpoint', $endpoint);
        }
        $model = getenv('EVAL_HARNESS_JUDGE_MODEL');
        if (is_string($model) && $model !== '') {
            config()->set('eval-harness.metrics.llm_as_judge.model', $model);
        }
    }

    public function test_real_judge_scores_identical_strings_high(): void
    {
        /** @var LlmAsJudgeMetric $metric */
        $metric = $this->app->make(LlmAsJudgeMetric::class);
        $sample = new DatasetSample(
            id: 'live.identical',
            input: ['question' => 'What is 2 + 2?'],
            expectedOutput: 'The answer is 4.',
        );

        $score = $metric->score($sample, 'The answer is 4.');

        $this->assertGreaterThanOrEqual(0.9, $score->score);
    }
}
