<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Strict, case-sensitive, byte-equality string match.
 *
 * Score is exactly 1.0 on equality, 0.0 otherwise — no partial
 * credit. Whitespace is significant: "Paris" !== "Paris ". For
 * looser matching, use {@see CosineEmbeddingMetric}.
 *
 * Sample contract: `expected_output` MUST be a string.
 */
final class ExactMatchMetric implements Metric
{
    public function name(): string
    {
        return 'exact-match';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        if (! is_string($sample->expectedOutput)) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' expected_output must be a string for exact-match metric; got %s.",
                    $sample->id,
                    get_debug_type($sample->expectedOutput),
                ),
            );
        }

        $matched = $sample->expectedOutput === $actualOutput;

        return new MetricScore(
            score: $matched ? 1.0 : 0.0,
            details: [
                'expected' => $sample->expectedOutput,
                'actual' => $actualOutput,
                'match' => $matched,
            ],
        );
    }
}
