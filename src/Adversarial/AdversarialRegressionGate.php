<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Adversarial;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Compares the current adversarial run with a previous manifest baseline.
 */
final class AdversarialRegressionGate
{
    /** @var array<string, true> */
    private const AGGREGATES = [
        'mean' => true,
        'p50' => true,
        'p95' => true,
        'pass_rate' => true,
    ];

    /**
     * @param  list<string>  $metricTargets
     */
    public function assertConfiguration(float $maxDrop, array $metricTargets = []): void
    {
        $this->assertMaxDrop($maxDrop);
        $this->normalizeMetricTargets($metricTargets);
    }

    /**
     * @param  list<string>  $metricTargets
     */
    public function evaluate(
        AdversarialRunManifestEntry $current,
        ?AdversarialRunManifestEntry $baseline,
        float $maxDrop,
        array $metricTargets = [],
    ): AdversarialRegressionGateResult {
        $this->assertMaxDrop($maxDrop);
        $metricTargets = $this->normalizeMetricTargets($metricTargets);

        if ($baseline === null) {
            $checks = [];
            foreach ($metricTargets as $target) {
                if ($this->metricAggregateScore($current, $target['metric'], $target['aggregate']) !== null) {
                    continue;
                }

                $checks[] = $this->check(
                    target: sprintf('metrics.%s.%s', $target['metric'], $target['aggregate']),
                    baselineScore: null,
                    currentScore: null,
                    maxDrop: $maxDrop,
                );
            }

            if ($checks !== []) {
                return new AdversarialRegressionGateResult(
                    status: AdversarialRegressionGateResult::STATUS_FAIL,
                    currentRunId: $current->runId,
                    baselineRunId: null,
                    checks: $checks,
                );
            }

            return new AdversarialRegressionGateResult(
                status: AdversarialRegressionGateResult::STATUS_MISSING_BASELINE,
                currentRunId: $current->runId,
                baselineRunId: null,
                checks: [],
            );
        }

        $checks = [
            $this->check(
                target: 'macro_f1',
                baselineScore: $baseline->macroF1,
                currentScore: $current->macroF1,
                maxDrop: $maxDrop,
            ),
        ];

        foreach ($metricTargets as $target) {
            $checks[] = $this->check(
                target: sprintf('metrics.%s.%s', $target['metric'], $target['aggregate']),
                baselineScore: $this->metricAggregateScore($baseline, $target['metric'], $target['aggregate']),
                currentScore: $this->metricAggregateScore($current, $target['metric'], $target['aggregate']),
                maxDrop: $maxDrop,
            );
        }

        $status = AdversarialRegressionGateResult::STATUS_PASS;
        foreach ($checks as $check) {
            if ($check->failed()) {
                $status = AdversarialRegressionGateResult::STATUS_FAIL;
                break;
            }
        }

        return new AdversarialRegressionGateResult(
            status: $status,
            currentRunId: $current->runId,
            baselineRunId: $baseline->runId,
            checks: $checks,
        );
    }

    private function assertMaxDrop(float $maxDrop): void
    {
        if ($maxDrop < 0.0 || $maxDrop > 1.0 || is_nan($maxDrop) || is_infinite($maxDrop)) {
            throw new EvalRunException('Adversarial regression gate max drop must be a finite ratio in [0, 1].');
        }
    }

    private function check(string $target, ?float $baselineScore, ?float $currentScore, float $maxDrop): AdversarialRegressionGateCheck
    {
        if ($baselineScore === null || $currentScore === null) {
            return new AdversarialRegressionGateCheck(
                target: $target,
                baselineScore: $baselineScore,
                currentScore: $currentScore,
                drop: null,
                maxDrop: $maxDrop,
                status: AdversarialRegressionGateCheck::STATUS_MISSING_VALUE,
            );
        }

        $drop = max(0.0, $baselineScore - $currentScore);

        return new AdversarialRegressionGateCheck(
            target: $target,
            baselineScore: $baselineScore,
            currentScore: $currentScore,
            drop: $drop,
            maxDrop: $maxDrop,
            status: $drop > $maxDrop + 0.000000001
                ? AdversarialRegressionGateCheck::STATUS_FAIL
                : AdversarialRegressionGateCheck::STATUS_PASS,
        );
    }

    /**
     * @param  list<string>  $metricTargets
     * @return list<array{metric: string, aggregate: string}>
     */
    private function normalizeMetricTargets(array $metricTargets): array
    {
        if (! array_is_list($metricTargets)) {
            throw new EvalRunException('Adversarial regression gate metric targets must be a zero-based list.');
        }

        $normalized = [];
        foreach ($metricTargets as $index => $target) {
            if (! is_string($target) || $target === '' || $target !== trim($target)) {
                throw new EvalRunException(sprintf('Adversarial regression gate metric target at index %d must be a non-empty string without leading or trailing whitespace.', $index));
            }

            $parts = explode(':', $target);
            if (count($parts) > 2) {
                throw new EvalRunException(sprintf("Adversarial regression gate metric target '%s' must use metric or metric:aggregate syntax.", $target));
            }

            $metricName = $parts[0];
            $aggregate = $parts[1] ?? 'mean';
            if ($metricName === '' || $metricName !== trim($metricName) || $aggregate === '' || $aggregate !== trim($aggregate)) {
                throw new EvalRunException(sprintf("Adversarial regression gate metric target '%s' must use metric or metric:aggregate syntax.", $target));
            }

            if (! isset(self::AGGREGATES[$aggregate])) {
                throw new EvalRunException(sprintf(
                    "Adversarial regression gate metric target '%s' uses unsupported aggregate '%s'. Supported aggregates: %s.",
                    $target,
                    $aggregate,
                    implode(', ', array_keys(self::AGGREGATES)),
                ));
            }

            $normalized[$metricName.':'.$aggregate] = [
                'metric' => $metricName,
                'aggregate' => $aggregate,
            ];
        }

        return array_values($normalized);
    }

    private function metricAggregateScore(AdversarialRunManifestEntry $entry, string $metricName, string $aggregate): ?float
    {
        $metric = $entry->metrics[$metricName] ?? null;
        if ($metric === null) {
            return null;
        }

        return $metric[$aggregate];
    }
}
