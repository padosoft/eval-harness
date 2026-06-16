<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online;

use Closure;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Padosoft\EvalHarness\Support\RuntimeOptions;

/**
 * Config-driven gate that decides whether a single live interaction is
 * sampled for online judging.
 *
 * Returns false immediately when online monitoring is disabled or the
 * sampling rate is <= 0; returns true when the rate is >= 1; otherwise
 * draws a uniform random number and compares it to the rate. The
 * randomizer is injectable so tests stay deterministic.
 */
final class OnlineSamplingDecision
{
    /**
     * @param  (Closure(): float)|null  $randomizer  returns a float in [0, 1)
     */
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ?Closure $randomizer = null,
    ) {}

    public function shouldSample(): bool
    {
        if (! RuntimeOptions::normalizeBoolean($this->config->get('eval-harness.online.enabled'), false)) {
            return false;
        }

        $rate = RuntimeOptions::normalizeUnitInterval($this->config->get('eval-harness.online.sampling_rate'), 0.0);

        if ($rate <= 0.0) {
            return false;
        }

        if ($rate >= 1.0) {
            return true;
        }

        return $this->random() < $rate;
    }

    private function random(): float
    {
        if ($this->randomizer !== null) {
            return ($this->randomizer)();
        }

        // Divide by (max + 1) so the draw stays in [0, 1): a draw of
        // exactly 1.0 would otherwise leak a sample through at rate 0.
        return mt_rand() / (mt_getrandmax() + 1.0);
    }
}
