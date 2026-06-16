<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the recent online pass rate for a dataset dips below the
 * configured alert threshold. Host apps register a listener to route
 * this to their own alerting (Slack, PagerDuty, email, etc.); the
 * package itself performs no side effect beyond dispatching the event.
 */
final class OnlinePassRateDropped
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $dataset,
        public readonly float $passRate,
        public readonly float $threshold,
        public readonly int $sampleCount,
    ) {}
}
