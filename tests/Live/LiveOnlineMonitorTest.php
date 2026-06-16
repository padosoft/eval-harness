<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Live;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Padosoft\EvalHarness\Online\OnlineMonitor;
use Padosoft\EvalHarness\Online\OnlineScore;
use Padosoft\EvalHarness\Tests\TestCase;

/**
 * Opt-in live test for online monitoring against a real judge.
 *
 * Requires `EVAL_HARNESS_LIVE_API_KEY` (and optionally
 * `EVAL_HARNESS_JUDGE_ENDPOINT` / `EVAL_HARNESS_JUDGE_MODEL`). CI does
 * NOT run this suite — invoke explicitly:
 *
 *   EVAL_HARNESS_LIVE_API_KEY=sk-... vendor/bin/phpunit --testsuite Live
 *
 * It samples one captured interaction at 100% with the `sync` queue so
 * the judge job runs inline, then asserts a real OnlineScore row was
 * persisted.
 */
final class LiveOnlineMonitorTest extends TestCase
{
    use RefreshDatabase;

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

        config()->set('queue.default', 'sync');
        config()->set('eval-harness.online.enabled', true);
        config()->set('eval-harness.online.sampling_rate', 1.0);
        config()->set('eval-harness.online.metric', 'llm-as-judge');
    }

    public function test_real_capture_persists_online_score(): void
    {
        /** @var OnlineMonitor $monitor */
        $monitor = $this->app->make(OnlineMonitor::class);

        $captured = $monitor->capture(
            dataset: 'live.online',
            sampleId: 'live-1',
            input: ['question' => 'What is 2 + 2?'],
            expected: 'The answer is 4.',
            actual: 'The answer is 4.',
        );

        $this->assertTrue($captured);

        $row = OnlineScore::forDataset('live.online')->firstOrFail();
        $this->assertSame('llm-as-judge', $row->metric);
        $this->assertGreaterThanOrEqual(0.0, $row->score);
        $this->assertLessThanOrEqual(1.0, $row->score);
    }
}
