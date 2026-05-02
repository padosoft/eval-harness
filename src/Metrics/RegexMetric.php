<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Scores 1.0 when actual output matches the regex in expected_output.
 *
 * The expected output must be a complete PHP regex pattern including
 * delimiters, e.g. `/order #[0-9]+/i`.
 */
final class RegexMetric implements Metric
{
    public function name(): string
    {
        return 'regex';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        if (! is_string($sample->expectedOutput)) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' expected_output must be a string regex pattern for regex metric; got %s.",
                    $sample->id,
                    get_debug_type($sample->expectedOutput),
                ),
            );
        }

        $result = @preg_match($sample->expectedOutput, $actualOutput);
        if ($result === false) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' expected_output is not a valid regex pattern for regex metric: %s.",
                    $sample->id,
                    preg_last_error_msg(),
                ),
            );
        }

        $matched = $result === 1;

        return new MetricScore(
            score: $matched ? 1.0 : 0.0,
            details: [
                'pattern' => $sample->expectedOutput,
                'match' => $matched,
            ],
        );
    }
}
