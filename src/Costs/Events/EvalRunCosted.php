<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Costs\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Padosoft\EvalHarness\Costs\RunCost;

/**
 * The FinOps seam: what this run cost, attributed to a cost centre.
 *
 * Dispatched at the end of every run, whether or not anything is listening.
 * A FinOps package — `padosoft/laravel-ai-finops`, a home-grown ledger, a
 * Grafana exporter — subscribes and attributes the spend; neither side depends
 * on the other, which is the whole point of doing this as an event rather than
 * as an integration.
 *
 * ## Why evaluation spend needs its own cost centre
 *
 * Eval traffic looks exactly like production traffic to a provider dashboard:
 * same key, same model, same endpoint. So it lands in the same bucket, and the
 * first honest answer to "how much are we spending on quality?" is usually "we
 * cannot tell". Tagging it at the source — `eval:<dataset>` by default — makes
 * the question answerable, per dataset, which is the granularity at which
 * somebody can actually decide that a nightly thousand-row judge run is or is
 * not worth what it catches.
 *
 * The payload carries {@see RunCost::isComplete()} so a listener never charges
 * a total that excluded half the calls without knowing it did.
 */
final class EvalRunCosted
{
    use Dispatchable;

    public function __construct(
        public readonly string $dataset,
        public readonly string $costCenter,
        public readonly RunCost $cost,
        public readonly float $startedAt,
        public readonly float $finishedAt,
        public readonly int $rows,
        public readonly int $executions,
        public readonly bool $halted = false,
    ) {}
}
