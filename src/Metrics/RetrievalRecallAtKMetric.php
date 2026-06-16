<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Metrics\Retrieval\AbstractRetrievalRankingMetric;
use Padosoft\EvalHarness\Metrics\Retrieval\RankedRetrieval;

/**
 * recall@k: fraction of relevant ids found within the top-k retrieved
 * ids, i.e. |top-k ∩ relevant| / |relevant|, clamped to [0, 1].
 *
 * Alias `retrieval-recall-at-k` resolves via the container with zero
 * extra binding.
 */
final class RetrievalRecallAtKMetric extends AbstractRetrievalRankingMetric
{
    public function name(): string
    {
        return 'retrieval-recall-at-k';
    }

    protected function scoreRanked(RankedRetrieval $ranked, array $relevantGains, int $k): MetricScore
    {
        $topK = $ranked->topKIds($k);
        $relevant = array_keys($relevantGains);
        $found = count(array_intersect($topK, $relevant));
        $score = max(0.0, min(1.0, $found / count($relevant)));

        return new MetricScore($score, [
            'k' => $k,
            'relevant_count' => count($relevant),
            'found' => $found,
            'top_k' => $topK,
        ]);
    }
}
