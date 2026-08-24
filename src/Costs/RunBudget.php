<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Costs;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * A spending limit that can actually stop a run.
 *
 * An evaluation suite is the one workload where a loop bug costs real money in
 * a way nobody notices until the invoice: a thousand rows × three repetitions ×
 * an LLM judge is three thousand paid calls, and the difference between a
 * healthy run and a runaway one is a config value somebody changed. A budget
 * that only *reports* overspend after the fact is a receipt, not a control.
 *
 * So this one halts. The run stops at the row that crossed the line, keeps
 * every row it had already scored, and reports that it was halted — with what
 * it spent and how far it got.
 *
 * ## Why halting is safe, and stopping silently is not
 *
 * A halted run is **incomplete data**, and incomplete data that looks like a
 * pass is the worst possible outcome for a CI gate: the rows that would have
 * failed are simply the ones that never ran. So a halt is recorded in the
 * report, and the command exits non-zero. Green must mean "everything ran and
 * everything passed", never "we ran out of money before we got to the bad
 * rows".
 *
 * ## The limit is a ceiling on observable spend
 *
 * It bounds what the harness can see — the judge and embedding calls it makes,
 * plus whatever a system-under-test reports back. Unpriced models contribute
 * nothing to the number, which is the honest behaviour but means a budget over
 * an unpriced model does not bind: {@see RunCost::isComplete()} is how a caller
 * finds that out.
 */
final class RunBudget
{
    private bool $halted = false;

    private ?string $haltedReason = null;

    private int $rowsBeforeHalt = 0;

    private function __construct(
        public readonly ?float $limitUsd,
        private readonly CostLedger $ledger,
    ) {}

    public static function unlimited(CostLedger $ledger): self
    {
        return new self(null, $ledger);
    }

    public static function of(?float $limitUsd, CostLedger $ledger): self
    {
        if ($limitUsd !== null && (! is_finite($limitUsd) || $limitUsd <= 0.0)) {
            throw new EvalRunException(sprintf(
                'A run budget must be a positive amount in USD; got %s.',
                var_export($limitUsd, true),
            ));
        }

        return new self($limitUsd, $ledger);
    }

    /**
     * Charge one metric's provider usage to this run.
     *
     * Takes the metric's whole details array rather than the usage block, so
     * a metric that reports no usage is a no-op at the call site instead of a
     * conditional at every one of them.
     *
     * @param  array<string, mixed>  $metricDetails
     */
    public function record(array $metricDetails): void
    {
        $this->ledger->recordMetricDetails($metricDetails);
    }

    public function toRunCost(): RunCost
    {
        return $this->ledger->toRunCost();
    }

    public function spentUsd(): float
    {
        return $this->ledger->spentUsd();
    }

    public function remainingUsd(): ?float
    {
        return $this->limitUsd === null ? null : round($this->limitUsd - $this->spentUsd(), 8);
    }

    public function isExceeded(): bool
    {
        return $this->limitUsd !== null && $this->spentUsd() > $this->limitUsd;
    }

    /**
     * Record that the run is stopping here.
     *
     * @param  int  $completedRows  rows fully scored before the halt
     */
    public function halt(int $completedRows): void
    {
        $this->halted = true;
        $this->rowsBeforeHalt = $completedRows;
        $this->haltedReason = sprintf(
            'Spent $%s of a $%s budget after %d row%s.',
            number_format($this->spentUsd(), 4),
            number_format($this->limitUsd ?? 0.0, 4),
            $completedRows,
            $completedRows === 1 ? '' : 's',
        );
    }

    public function wasHalted(): bool
    {
        return $this->halted;
    }

    public function outcome(): BudgetOutcome
    {
        return new BudgetOutcome(
            limitUsd: $this->limitUsd,
            spentUsd: $this->spentUsd(),
            halted: $this->halted,
            completedRows: $this->rowsBeforeHalt,
            reason: $this->haltedReason,
        );
    }
}
