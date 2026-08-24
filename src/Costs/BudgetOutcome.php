<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Costs;

/**
 * What a budget did to a run, recorded in the report.
 *
 * Present even when nothing was capped, because "this run had no budget" and
 * "this run had a budget and stayed inside it" are different facts and a reader
 * three weeks later cannot tell them apart from an absent field.
 */
final class BudgetOutcome
{
    public function __construct(
        public readonly ?float $limitUsd,
        public readonly float $spentUsd,
        public readonly bool $halted,
        public readonly int $completedRows,
        public readonly ?string $reason,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'limit_usd' => $this->limitUsd === null ? null : round($this->limitUsd, 6),
            'spent_usd' => round($this->spentUsd, 6),
            'halted' => $this->halted,
            'completed_rows' => $this->completedRows,
            'reason' => $this->reason,
        ];
    }
}
