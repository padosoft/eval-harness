<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Offline ROUGE-L F1 over lower-cased word tokens.
 *
 * This is a deterministic baseline, not a replacement for
 * embedding/LLM semantic grading. It is useful for CI checks where
 * word-order overlap matters and network calls are not allowed.
 */
final class RougeLMetric implements Metric
{
    public function name(): string
    {
        return 'rouge-l';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        if (! is_string($sample->expectedOutput)) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' expected_output must be a string for rouge-l metric; got %s.",
                    $sample->id,
                    get_debug_type($sample->expectedOutput),
                ),
            );
        }

        $expectedTokens = $this->tokens($sample->expectedOutput, $sample->id, 'expected_output');
        $actualTokens = $this->tokens($actualOutput, $sample->id, 'actual_output');

        if ($expectedTokens === [] && $actualTokens === []) {
            return new MetricScore(1.0, ['lcs_tokens' => 0, 'precision' => 1.0, 'recall' => 1.0]);
        }

        if ($expectedTokens === [] || $actualTokens === []) {
            return new MetricScore(0.0, ['lcs_tokens' => 0, 'precision' => 0.0, 'recall' => 0.0]);
        }

        $lcs = $this->lcsLength($expectedTokens, $actualTokens);
        $precision = $lcs / count($actualTokens);
        $recall = $lcs / count($expectedTokens);
        $score = ($precision + $recall) === 0.0
            ? 0.0
            : (2.0 * $precision * $recall) / ($precision + $recall);

        return new MetricScore(
            score: $score,
            details: [
                'lcs_tokens' => $lcs,
                'precision' => $precision,
                'recall' => $recall,
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value, string $sampleId, string $field): array
    {
        if (preg_match('//u', $value) !== 1) {
            throw new MetricException(
                sprintf("Sample '%s' %s must be valid UTF-8 for rouge-l metric.", $sampleId, $field),
            );
        }

        $lowercased = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);

        $result = preg_match_all('/[[:alnum:]]+/u', $lowercased, $matches);
        if ($result === false) {
            throw new MetricException(
                sprintf("Sample '%s' %s could not be tokenized for rouge-l metric.", $sampleId, $field),
            );
        }

        /** @var list<string> $tokens */
        $tokens = $matches[0];

        return $tokens;
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     */
    private function lcsLength(array $left, array $right): int
    {
        $previous = array_fill(0, count($right) + 1, 0);

        foreach ($left as $leftToken) {
            $current = [0];

            foreach ($right as $index => $rightToken) {
                $current[] = $leftToken === $rightToken
                    ? $previous[$index] + 1
                    : max($previous[$index + 1], $current[$index]);
            }

            $previous = $current;
        }

        return $previous[count($right)];
    }
}
