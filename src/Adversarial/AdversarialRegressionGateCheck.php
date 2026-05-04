<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Adversarial;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * One normalized-score comparison inside an adversarial regression gate.
 */
final class AdversarialRegressionGateCheck
{
    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    public const STATUS_MISSING_VALUE = 'missing-value';

    private const STATUSES = [
        self::STATUS_PASS => true,
        self::STATUS_FAIL => true,
        self::STATUS_MISSING_VALUE => true,
    ];

    public function __construct(
        public readonly string $target,
        public readonly ?float $baselineScore,
        public readonly ?float $currentScore,
        public readonly ?float $drop,
        public readonly float $maxDrop,
        public readonly string $status,
    ) {
        if ($target === '' || $target !== trim($target)) {
            throw new EvalRunException('Adversarial regression gate check target must be a non-empty string without leading or trailing whitespace.');
        }

        foreach ([
            'baseline_score' => $baselineScore,
            'current_score' => $currentScore,
            'drop' => $drop,
            'max_drop' => $maxDrop,
        ] as $field => $value) {
            if ($value === null) {
                continue;
            }

            if ($value < 0.0 || $value > 1.0 || is_nan($value) || is_infinite($value)) {
                throw new EvalRunException(sprintf('Adversarial regression gate check %s must be in [0, 1].', $field));
            }
        }

        if (! isset(self::STATUSES[$status])) {
            throw new EvalRunException(sprintf("Unsupported adversarial regression gate check status '%s'.", $status));
        }

        if ($status !== self::STATUS_MISSING_VALUE && ($baselineScore === null || $currentScore === null || $drop === null)) {
            throw new EvalRunException('Adversarial regression gate pass/fail checks require baseline, current, and drop scores.');
        }
    }

    public function failed(): bool
    {
        return $this->status !== self::STATUS_PASS;
    }

    /**
     * @return array{target: string, baseline_score: float|null, current_score: float|null, drop: float|null, max_drop: float, status: string}
     */
    public function toJson(): array
    {
        return [
            'target' => $this->target,
            'baseline_score' => $this->baselineScore,
            'current_score' => $this->currentScore,
            'drop' => $this->drop,
            'max_drop' => $this->maxDrop,
            'status' => $this->status,
        ];
    }
}
