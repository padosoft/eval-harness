<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Online;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Production entry point: the host app calls {@see self::capture()}
 * from its live AI path. The monitor consults {@see OnlineSamplingDecision};
 * on a sampling hit it dispatches a {@see JudgeLiveSampleJob} onto the
 * configured queue and returns true, otherwise it returns false without
 * side effects.
 */
final class OnlineMonitor
{
    public function __construct(
        private readonly OnlineSamplingDecision $sampling,
        private readonly Dispatcher $bus,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function capture(string $dataset, string $sampleId, array $input, string $expected, string $actual): bool
    {
        if (! $this->sampling->shouldSample()) {
            return false;
        }

        $queue = $this->config->get('eval-harness.online.queue');
        $connection = $this->config->get('eval-harness.online.connection');

        $this->bus->dispatch(new JudgeLiveSampleJob(
            dataset: $dataset,
            sampleId: $sampleId,
            input: $input,
            expected: $expected,
            actual: $actual,
            queue: is_string($queue) ? $queue : null,
            connection: is_string($connection) ? $connection : null,
        ));

        return true;
    }
}
