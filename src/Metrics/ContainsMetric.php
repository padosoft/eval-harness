<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Scores 1.0 when the actual output contains the expected string.
 *
 * This is intentionally case-sensitive and byte-oriented, matching
 * ExactMatchMetric's deterministic behavior while allowing additional
 * context around the golden answer.
 */
final class ContainsMetric implements Metric
{
    public function name(): string
    {
        return 'contains';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        if (! is_string($sample->expectedOutput)) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' expected_output must be a string for contains metric; got %s.",
                    $sample->id,
                    get_debug_type($sample->expectedOutput),
                ),
            );
        }

        if ($sample->expectedOutput === '') {
            throw new MetricException(
                sprintf("Sample '%s' expected_output must not be empty for contains metric.", $sample->id),
            );
        }

        $matched = str_contains($actualOutput, $sample->expectedOutput);

        return new MetricScore(
            score: $matched ? 1.0 : 0.0,
            details: [
                'expected_substring' => $sample->expectedOutput,
                'match' => $matched,
            ],
        );
    }
}
