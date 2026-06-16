<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics\Retrieval;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\Metric;
use Padosoft\EvalHarness\Metrics\MetricScore;

/**
 * Shared base for id-based retrieval-ranking metrics. Parses the
 * {@see RankedRetrieval} from `actualOutput`, the relevant ids/gains
 * from the sample's `expected_output`, resolves `k`
 * (metadata.k > ctor k > config), and delegates the math to
 * {@see self::scoreRanked()}.
 *
 * Resolution path: `resolve('retrieval-*')` →
 * `container->make(<metric>::class)` auto-wires the {@see ConfigRepository}
 * and leaves `?int $k = null`, so the alias works with zero extra
 * binding; per-sample `metadata.k` still overrides the configured
 * `default_k` at score time.
 */
abstract class AbstractRetrievalRankingMetric implements Metric
{
    public function __construct(
        protected readonly ConfigRepository $config,
        protected readonly ?int $k = null,
    ) {}

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        $ranked = RankedRetrieval::fromActualOutput($actualOutput, $sample->id);
        $gains = RankedRetrieval::relevanceGainsFromExpected($sample->expectedOutput, $sample->id);
        $k = $this->resolveK($sample);

        return $this->scoreRanked($ranked, $gains, $k);
    }

    /**
     * @param  array<string, float>  $relevantGains  id => gain (binary 1.0 when not graded)
     */
    abstract protected function scoreRanked(RankedRetrieval $ranked, array $relevantGains, int $k): MetricScore;

    protected function resolveK(DatasetSample $sample): int
    {
        $override = $sample->metadata['k'] ?? null;
        if (is_int($override) && $override > 0) {
            return $override;
        }

        if ($this->k !== null) {
            if ($this->k < 1) {
                throw new MetricException('Retrieval metric k must be a positive integer.');
            }

            return $this->k;
        }

        $configured = $this->config->get('eval-harness.metrics.retrieval.default_k', 5);

        return is_int($configured) && $configured > 0 ? $configured : 5;
    }
}
