<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Adversarial;

use Padosoft\EvalHarness\Adversarial\AdversarialRegressionGate;
use Padosoft\EvalHarness\Adversarial\AdversarialRegressionGateCheck;
use Padosoft\EvalHarness\Adversarial\AdversarialRegressionGateResult;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifestEntry;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use PHPUnit\Framework\TestCase;

final class AdversarialRegressionGateTest extends TestCase
{
    public function test_missing_baseline_is_explicit_non_failure_status(): void
    {
        $result = (new AdversarialRegressionGate)->evaluate(
            current: $this->entry('current', 0.8),
            baseline: null,
            maxDrop: 0.05,
        );

        $this->assertSame(AdversarialRegressionGateResult::STATUS_MISSING_BASELINE, $result->status);
        $this->assertTrue($result->missingBaseline());
        $this->assertTrue($result->passed());
        $this->assertFalse($result->failed());
        $this->assertSame([], $result->checks);
    }

    public function test_missing_configured_current_metric_fails_closed_without_baseline(): void
    {
        $result = (new AdversarialRegressionGate)->evaluate(
            current: $this->entry('current', 0.95, ['rouge-l' => $this->aggregate(0.95)]),
            baseline: null,
            maxDrop: 0.05,
            metricTargets: ['exact-match'],
        );

        $this->assertSame(AdversarialRegressionGateResult::STATUS_FAIL, $result->status);
        $this->assertNull($result->baselineRunId);
        $this->assertSame('metrics.exact-match.mean', $result->checks[0]->target);
        $this->assertSame(AdversarialRegressionGateCheck::STATUS_MISSING_VALUE, $result->checks[0]->status);
        $this->assertNull($result->checks[0]->baselineScore);
        $this->assertNull($result->checks[0]->currentScore);
    }

    public function test_passes_when_macro_f1_drop_is_within_threshold(): void
    {
        $result = (new AdversarialRegressionGate)->evaluate(
            current: $this->entry('current', 0.96),
            baseline: $this->entry('baseline', 1.0),
            maxDrop: 0.05,
        );

        $this->assertSame(AdversarialRegressionGateResult::STATUS_PASS, $result->status);
        $this->assertSame('baseline', $result->baselineRunId);
        $this->assertSame('macro_f1', $result->checks[0]->target);
        $this->assertSame(AdversarialRegressionGateCheck::STATUS_PASS, $result->checks[0]->status);
        $this->assertEqualsWithDelta(0.04, $result->checks[0]->drop, 1e-9);
    }

    public function test_fails_when_macro_f1_drop_exceeds_threshold(): void
    {
        $result = (new AdversarialRegressionGate)->evaluate(
            current: $this->entry('current', 0.89),
            baseline: $this->entry('baseline', 1.0),
            maxDrop: 0.05,
        );

        $this->assertSame(AdversarialRegressionGateResult::STATUS_FAIL, $result->status);
        $this->assertTrue($result->failed());
        $this->assertSame(AdversarialRegressionGateCheck::STATUS_FAIL, $result->checks[0]->status);
        $this->assertEqualsWithDelta(0.11, $result->checks[0]->drop, 1e-9);
    }

    public function test_fails_when_configured_metric_aggregate_drops(): void
    {
        $result = (new AdversarialRegressionGate)->evaluate(
            current: $this->entry('current', 0.95, ['exact-match' => $this->aggregate(0.80)]),
            baseline: $this->entry('baseline', 0.95, ['exact-match' => $this->aggregate(0.90)]),
            maxDrop: 0.05,
            metricTargets: ['exact-match:mean'],
        );

        $this->assertSame(AdversarialRegressionGateResult::STATUS_FAIL, $result->status);
        $this->assertSame('metrics.exact-match.mean', $result->checks[1]->target);
        $this->assertSame(AdversarialRegressionGateCheck::STATUS_FAIL, $result->checks[1]->status);
        $this->assertEqualsWithDelta(0.10, $result->checks[1]->drop, 1e-9);
    }

    public function test_missing_configured_metric_fails_closed(): void
    {
        $result = (new AdversarialRegressionGate)->evaluate(
            current: $this->entry('current', 0.95, ['rouge-l' => $this->aggregate(0.95)]),
            baseline: $this->entry('baseline', 0.95, ['exact-match' => $this->aggregate(0.95)]),
            maxDrop: 0.05,
            metricTargets: ['exact-match'],
        );

        $this->assertSame(AdversarialRegressionGateResult::STATUS_FAIL, $result->status);
        $this->assertSame('metrics.exact-match.mean', $result->checks[1]->target);
        $this->assertSame(AdversarialRegressionGateCheck::STATUS_MISSING_VALUE, $result->checks[1]->status);
        $this->assertSame(0.95, $result->checks[1]->baselineScore);
        $this->assertNull($result->checks[1]->currentScore);
    }

    public function test_rejects_unsupported_metric_aggregate(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("uses unsupported aggregate 'median'");

        (new AdversarialRegressionGate)->evaluate(
            current: $this->entry('current', 0.95),
            baseline: $this->entry('baseline', 0.95),
            maxDrop: 0.05,
            metricTargets: ['exact-match:median'],
        );
    }

    public function test_rejects_metric_target_with_inner_whitespace(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must use metric or metric:aggregate syntax');

        (new AdversarialRegressionGate)->assertConfiguration(
            maxDrop: 0.05,
            metricTargets: ['exact-match :mean'],
        );
    }

    public function test_evaluate_rejects_invalid_metric_target_even_without_baseline(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must use metric or metric:aggregate syntax');

        (new AdversarialRegressionGate)->evaluate(
            current: $this->entry('current', 0.95),
            baseline: null,
            maxDrop: 0.05,
            metricTargets: ['exact-match :mean'],
        );
    }

    public function test_check_rejects_status_that_contradicts_scores(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('status does not match the configured max_drop');

        new AdversarialRegressionGateCheck(
            target: 'macro_f1',
            baselineScore: 1.0,
            currentScore: 0.80,
            drop: 0.20,
            maxDrop: 0.05,
            status: AdversarialRegressionGateCheck::STATUS_PASS,
        );
    }

    public function test_result_rejects_status_that_contradicts_checks(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('pass results cannot contain failing checks');

        new AdversarialRegressionGateResult(
            status: AdversarialRegressionGateResult::STATUS_PASS,
            currentRunId: 'current',
            baselineRunId: 'baseline',
            checks: [
                new AdversarialRegressionGateCheck(
                    target: 'macro_f1',
                    baselineScore: 1.0,
                    currentScore: 0.80,
                    drop: 0.20,
                    maxDrop: 0.05,
                    status: AdversarialRegressionGateCheck::STATUS_FAIL,
                ),
            ],
        );
    }

    /**
     * @param  array<string, array{mean: float, p50: float, p95: float, pass_rate: float}>|null  $metrics
     */
    private function entry(string $runId, float $macroF1, ?array $metrics = null): AdversarialRunManifestEntry
    {
        $metrics ??= ['exact-match' => $this->aggregate($macroF1)];

        return new AdversarialRunManifestEntry(
            runId: $runId,
            datasetName: 'adversarial.security.v1',
            reportSchemaVersion: 'eval-harness.report.v1',
            startedAt: 1.0,
            finishedAt: 2.0,
            durationSeconds: 1.0,
            totalSamples: 1,
            totalFailures: 0,
            macroF1: $macroF1,
            metrics: $metrics,
            adversarial: [
                'total_samples' => 1,
                'categories' => [[
                    'category' => 'prompt-injection',
                    'label' => 'Prompt injection',
                    'severity' => 'high',
                    'sample_count' => 1,
                    'compliance_frameworks' => ['OWASP LLM'],
                    'metrics' => $metrics,
                ]],
                'compliance_frameworks' => [[
                    'framework' => 'OWASP LLM',
                    'sample_count' => 1,
                    'categories' => ['prompt-injection'],
                ]],
            ],
        );
    }

    /**
     * @return array{mean: float, p50: float, p95: float, pass_rate: float}
     */
    private function aggregate(float $score): array
    {
        return [
            'mean' => $score,
            'p50' => $score,
            'p95' => $score,
            'pass_rate' => $score,
        ];
    }
}
