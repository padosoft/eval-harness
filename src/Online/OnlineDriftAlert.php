<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Padosoft\EvalHarness\Online\Events\OnlinePassRateDropped;
use Padosoft\EvalHarness\Support\RuntimeOptions;

/**
 * Detects online pass-rate drift for a dataset over the most recent
 * `alert.window` scores. When at least `alert.min_samples` rows exist
 * and the pass rate dips below `alert.threshold`, it dispatches
 * {@see OnlinePassRateDropped} so host apps can alert; otherwise it is
 * a no-op. Keeping the side effect to a single event respects
 * rule-exception-handling / rule-logging-security (the package stays
 * side-effect-light).
 */
final class OnlineDriftAlert
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Dispatcher $events,
    ) {}

    public function evaluate(string $dataset): bool
    {
        $window = RuntimeOptions::normalizePositiveInt($this->config->get('eval-harness.online.alert.window'), 50);
        $minSamples = RuntimeOptions::normalizePositiveInt($this->config->get('eval-harness.online.alert.min_samples'), 20);
        $threshold = RuntimeOptions::normalizeUnitInterval($this->config->get('eval-harness.online.alert.threshold'), 0.8);

        $recent = OnlineScore::forDataset($dataset)
            ->orderByDesc('judged_at')
            ->orderByDesc('id')
            ->limit($window)
            ->pluck('passed')
            ->map(static fn (mixed $passed): bool => (bool) $passed)
            ->all();

        $count = count($recent);
        if ($count < $minSamples) {
            return false;
        }

        $passed = count(array_filter($recent));
        $passRate = $passed / $count;

        if ($passRate >= $threshold) {
            return false;
        }

        $this->events->dispatch(new OnlinePassRateDropped(
            dataset: $dataset,
            passRate: $passRate,
            threshold: $threshold,
            sampleCount: $count,
        ));

        return true;
    }
}
