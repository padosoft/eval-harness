<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Console;

use Illuminate\Support\Facades\Http;
use Padosoft\EvalHarness\Contracts\JudgeClient;
use Padosoft\EvalHarness\Tests\TestCase;

final class CalibrateJudgeCommandTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../Fixtures/calibration/judge-cases.v1.yaml';

    protected function setUp(): void
    {
        parent::setUp();

        // Defensive: no real provider call should ever leak from this test.
        Http::fake();
    }

    /**
     * @param  list<float>  $scores
     */
    private function bindJudge(array $scores): void
    {
        $this->app->instance(JudgeClient::class, new class($scores) implements JudgeClient
        {
            /** @param list<float> $scores */
            public function __construct(private array $scores) {}

            public function judge(string $prompt): string
            {
                $score = array_shift($this->scores) ?? 0.0;

                return json_encode(['score' => $score, 'reason' => 'scripted'], JSON_THROW_ON_ERROR);
            }
        });
    }

    public function test_high_agreement_returns_success_with_json(): void
    {
        // Fixture verdicts: pass, fail, pass, fail. Judge scores below match perfectly.
        $this->bindJudge([0.9, 0.1, 0.85, 0.2]);

        $this->artisan('eval-harness:calibrate-judge', [
            'cases' => self::FIXTURE,
            '--json' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('"agreement_rate"');
    }

    public function test_low_agreement_fails(): void
    {
        // All judged pass => disagrees on the two fail cases => agreement 0.5 < 0.8 floor.
        $this->bindJudge([0.9, 0.9, 0.9, 0.9]);

        $this->artisan('eval-harness:calibrate-judge', [
            'cases' => self::FIXTURE,
        ])->assertExitCode(1);
    }

    public function test_self_preference_fails(): void
    {
        $this->bindJudge([0.9, 0.1, 0.85, 0.2]);
        config()->set('eval-harness.metrics.llm_as_judge.model', 'gpt-4o-mini');

        $this->artisan('eval-harness:calibrate-judge', [
            'cases' => self::FIXTURE,
            '--model-under-test' => 'gpt-4o-mini',
        ])->assertExitCode(1);
    }
}
