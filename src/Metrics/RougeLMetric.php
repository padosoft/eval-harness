<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use InvalidArgumentException;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Offline ROUGE-L F1 over lower-cased word tokens.
 *
 * This is a deterministic baseline, not a replacement for
 * embedding/LLM semantic grading. It is useful for CI checks where
 * word-order overlap matters and network calls are not allowed.
 *
 * ROUGE-L uses an O(n*m) LCS pass, so each field is capped at 1024
 * tokens by default. Inject a larger cap only for trusted datasets.
 */
final class RougeLMetric implements Metric
{
    private const int DEFAULT_MAX_TOKENS = 1024;

    public function __construct(private readonly int $maxTokens = self::DEFAULT_MAX_TOKENS)
    {
        if ($maxTokens < 1) {
            throw new InvalidArgumentException('ROUGE-L max token count must be at least 1.');
        }
    }

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

        $lowercased = mb_strtolower($value, 'UTF-8');

        $result = preg_match_all('/[[:alnum:]]+/u', $lowercased, $matches);
        if ($result === false) {
            throw new MetricException(
                sprintf("Sample '%s' %s could not be tokenized for rouge-l metric.", $sampleId, $field),
            );
        }

        /** @var list<string> $tokens */
        $tokens = $matches[0];

        $tokenCount = count($tokens);
        if ($tokenCount > $this->maxTokens) {
            throw new MetricException(sprintf(
                "Sample '%s' %s has %d tokens; rouge-l metric supports at most %d tokens per field.",
                $sampleId,
                $field,
                $tokenCount,
                $this->maxTokens,
            ));
        }

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
