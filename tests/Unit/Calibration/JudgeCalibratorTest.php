<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Calibration;

use Illuminate\Config\Repository;
use Padosoft\EvalHarness\Calibration\HumanLabel;
use Padosoft\EvalHarness\Calibration\JudgeCalibrator;
use Padosoft\EvalHarness\Contracts\JudgeClient;
use PHPUnit\Framework\TestCase;

/**
 * Returns a scripted score for each successive judge() call.
 */
final class ScriptedJudgeClient implements JudgeClient
{
    /** @param list<float> $scores */
    public function __construct(private array $scores) {}

    public function judge(string $prompt): string
    {
        $score = array_shift($this->scores) ?? 0.0;

        return json_encode(['score' => $score, 'reason' => 'scripted'], JSON_THROW_ON_ERROR);
    }
}

final class JudgeCalibratorTest extends TestCase
{
    private function config(float $passThreshold = 0.5, bool $requireDistinct = true): Repository
    {
        return new Repository([
            'eval-harness' => [
                'calibration' => [
                    'verdict_pass_threshold' => $passThreshold,
                    'require_distinct_models' => $requireDistinct,
                ],
            ],
        ]);
    }

    /**
     * @param  list<string>  $verdicts
     * @param  list<string>  $actuals
     * @return list<HumanLabel>
     */
    private function cases(array $verdicts, array $actuals): array
    {
        $labels = [];
        foreach ($verdicts as $i => $verdict) {
            $labels[] = new HumanLabel(
                id: 'c'.$i,
                input: ['question' => 'q'],
                expected: 'Paris',
                actual: $actuals[$i],
                humanVerdict: $verdict,
            );
        }

        return $labels;
    }

    public function test_agreement_and_confusion(): void
    {
        $cases = $this->cases(
            ['pass', 'fail', 'pass', 'fail'],
            ['aaaa', 'bbbb', 'cccc', 'dddd'],
        );
        $judge = new ScriptedJudgeClient([0.9, 0.1, 0.9, 0.8]); // verdicts: pass, fail, pass, pass

        $report = (new JudgeCalibrator($judge, $this->config()))->run($cases);

        $this->assertEqualsWithDelta(0.75, $report->agreementRate(), 1e-9);
        $this->assertSame(
            ['true_pass' => 2, 'true_fail' => 1, 'false_pass' => 1, 'false_fail' => 0],
            $report->confusion(),
        );
        $this->assertTrue($report->meetsThreshold(0.7));
        $this->assertFalse($report->meetsThreshold(0.8));
        $this->assertSame(4, $report->caseCount());
    }

    public function test_length_bias_is_detected(): void
    {
        // Increasing length paired with increasing judge score => high positive correlation.
        $cases = $this->cases(
            ['pass', 'pass', 'pass', 'pass'],
            ['a', 'bb', 'ccc', 'dddd'],
        );
        $judge = new ScriptedJudgeClient([0.1, 0.4, 0.7, 1.0]);

        $report = (new JudgeCalibrator($judge, $this->config()))->run($cases);

        $this->assertGreaterThan(0.4, $report->lengthBiasCorrelation());
        $this->assertTrue($report->lengthBiasWarned(0.4));
    }

    public function test_self_preference_violation(): void
    {
        $cases = $this->cases(['pass'], ['aaaa']);
        $judge = new ScriptedJudgeClient([0.9]);

        $report = (new JudgeCalibrator($judge, $this->config()))->run(
            $cases,
            judgeModel: 'gpt-4o-mini',
            modelUnderTest: 'gpt-4o-mini',
        );

        $this->assertTrue($report->selfPreferenceViolation());
    }

    public function test_distinct_models_pass_the_guard(): void
    {
        $cases = $this->cases(['pass'], ['aaaa']);
        $judge = new ScriptedJudgeClient([0.9]);

        $report = (new JudgeCalibrator($judge, $this->config()))->run(
            $cases,
            judgeModel: 'gpt-4o-mini',
            modelUnderTest: 'my-rag-model',
        );

        $this->assertFalse($report->selfPreferenceViolation());
    }
}
