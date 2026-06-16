<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Metrics\Retrieval\AbstractRetrievalRankingMetric;
use Padosoft\EvalHarness\Metrics\Retrieval\RankedRetrieval;

/**
 * hit@k: 1.0 when any relevant id appears in the top-k retrieved ids,
 * else 0.0. Domain-agnostic; the host app supplies the ranked ids.
 *
 * Alias `retrieval-hit-at-k` resolves via the container with zero
 * extra binding (auto-wired ConfigRepository, k defaults to config).
 */
final class RetrievalHitAtKMetric extends AbstractRetrievalRankingMetric
{
    public function name(): string
    {
        return 'retrieval-hit-at-k';
    }

    protected function scoreRanked(RankedRetrieval $ranked, array $relevantGains, int $k): MetricScore
    {
        $topK = $ranked->topKIds($k);
        $relevant = array_keys($relevantGains);
        $hit = array_intersect($topK, $relevant) !== [];

        return new MetricScore($hit ? 1.0 : 0.0, [
            'k' => $k,
            'relevant' => $relevant,
            'top_k' => $topK,
        ]);
    }
}
