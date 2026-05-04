<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Adversarial;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Result of comparing the current adversarial run with a previous baseline.
 */
final class AdversarialRegressionGateResult
{
    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    public const STATUS_MISSING_BASELINE = 'missing-baseline';

    private const STATUSES = [
        self::STATUS_PASS => true,
        self::STATUS_FAIL => true,
        self::STATUS_MISSING_BASELINE => true,
    ];

    /**
     * @param  list<AdversarialRegressionGateCheck>  $checks
     */
    public function __construct(
        public readonly string $status,
        public readonly string $currentRunId,
        public readonly ?string $baselineRunId,
        public readonly array $checks,
    ) {
        if (! isset(self::STATUSES[$status])) {
            throw new EvalRunException(sprintf("Unsupported adversarial regression gate status '%s'.", $status));
        }

        if ($currentRunId === '' || $currentRunId !== trim($currentRunId)) {
            throw new EvalRunException('Adversarial regression gate current run_id must be a non-empty string without leading or trailing whitespace.');
        }

        if ($baselineRunId !== null && ($baselineRunId === '' || $baselineRunId !== trim($baselineRunId))) {
            throw new EvalRunException('Adversarial regression gate baseline run_id must be null or a non-empty string without leading or trailing whitespace.');
        }

        if (! array_is_list($checks)) {
            throw new EvalRunException('Adversarial regression gate checks must be a zero-based list.');
        }

        foreach ($checks as $index => $check) {
            if (! $check instanceof AdversarialRegressionGateCheck) {
                throw new EvalRunException(sprintf(
                    'Adversarial regression gate check at index %d must be a %s instance; got %s.',
                    $index,
                    AdversarialRegressionGateCheck::class,
                    get_debug_type($check),
                ));
            }
        }

        if ($status === self::STATUS_MISSING_BASELINE && ($baselineRunId !== null || $checks !== [])) {
            throw new EvalRunException('Adversarial regression gate missing-baseline results cannot include a baseline run or checks.');
        }

        if ($status !== self::STATUS_MISSING_BASELINE && ($baselineRunId === null || $checks === [])) {
            throw new EvalRunException('Adversarial regression gate pass/fail results require a baseline run and at least one check.');
        }
    }

    public function passed(): bool
    {
        return $this->status !== self::STATUS_FAIL;
    }

    public function failed(): bool
    {
        return $this->status === self::STATUS_FAIL;
    }

    public function missingBaseline(): bool
    {
        return $this->status === self::STATUS_MISSING_BASELINE;
    }

    /**
     * @return array{status: string, current_run_id: string, baseline_run_id: string|null, checks: list<array{target: string, baseline_score: float|null, current_score: float|null, drop: float|null, max_drop: float, status: string}>}
     */
    public function toJson(): array
    {
        return [
            'status' => $this->status,
            'current_run_id' => $this->currentRunId,
            'baseline_run_id' => $this->baselineRunId,
            'checks' => array_map(
                static fn (AdversarialRegressionGateCheck $check): array => $check->toJson(),
                $this->checks,
            ),
        ];
    }
}
