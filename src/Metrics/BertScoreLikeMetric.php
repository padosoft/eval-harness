<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use InvalidArgumentException;
use Padosoft\EvalHarness\Contracts\EmbeddingClient;
use Padosoft\EvalHarness\Contracts\ProvidesUsageDetails;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Support\MetricUsageDetails;

/**
 * BERTScore-like token overlap powered by the configured embedding client.
 *
 * This is intentionally named "like": provider text embeddings are not a
 * drop-in contextual BERTScore implementation. The metric embeds normalized
 * expected/actual tokens, computes best-match cosine precision and recall,
 * then reports their F1. It gives Laravel users a fakeable semantic-overlap
 * signal without hard-coding a Python stack.
 */
final class BertScoreLikeMetric implements Metric, ProvidesUsageDetails
{
    private const int DEFAULT_MAX_TOKENS = 128;

    /**
     * @var array<string, int|float>
     */
    private array $usageDetails = [];

    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly int $maxTokens = self::DEFAULT_MAX_TOKENS,
    ) {
        if ($maxTokens < 1) {
            throw new InvalidArgumentException('BERTScore-like max token count must be at least 1.');
        }
    }

    public function name(): string
    {
        return 'bertscore-like';
    }

    public function usageDetails(): array
    {
        return $this->usageDetails;
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        $this->usageDetails = [];

        if (! is_string($sample->expectedOutput)) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' expected_output must be a string for bertscore-like metric; got %s.",
                    $sample->id,
                    get_debug_type($sample->expectedOutput),
                ),
            );
        }

        $expectedTokens = $this->tokens($sample->expectedOutput, $sample->id, 'expected_output');
        $actualTokens = $this->tokens($actualOutput, $sample->id, 'actual_output');

        if ($expectedTokens === [] && $actualTokens === []) {
            return new MetricScore(1.0, [
                'expected_tokens' => 0,
                'actual_tokens' => 0,
                'precision' => 1.0,
                'recall' => 1.0,
                'raw_score' => 1.0,
                'clamped_score' => 1.0,
            ]);
        }

        if ($expectedTokens === [] || $actualTokens === []) {
            return new MetricScore(0.0, [
                'expected_tokens' => count($expectedTokens),
                'actual_tokens' => count($actualTokens),
                'precision' => 0.0,
                'recall' => 0.0,
                'raw_score' => 0.0,
                'clamped_score' => 0.0,
            ]);
        }

        try {
            $vectors = $this->embeddings->embedMany([...$expectedTokens, ...$actualTokens]);
        } finally {
            $this->usageDetails = MetricUsageDetails::from($this->embeddings);
        }

        $expectedCount = count($expectedTokens);

        if (count($vectors) !== $expectedCount + count($actualTokens)) {
            throw new MetricException(
                sprintf(
                    'BERTScore-like embedding client returned %d vector(s); expected %d.',
                    count($vectors),
                    $expectedCount + count($actualTokens),
                ),
            );
        }

        $expectedVectors = array_slice($vectors, 0, $expectedCount);
        $actualVectors = array_slice($vectors, $expectedCount);

        $precision = $this->averageBestSimilarity($actualVectors, $expectedVectors);
        $recall = $this->averageBestSimilarity($expectedVectors, $actualVectors);
        $rawScore = ($precision + $recall) === 0.0
            ? 0.0
            : (2.0 * $precision * $recall) / ($precision + $recall);
        $clampedScore = self::clampScore($rawScore);

        $details = MetricUsageDetails::append([
            'expected_tokens' => $expectedCount,
            'actual_tokens' => count($actualTokens),
            'precision' => $precision,
            'recall' => $recall,
            'raw_score' => $rawScore,
            'clamped_score' => $clampedScore,
        ], $this);

        return new MetricScore(score: $clampedScore, details: $details);
    }

    /**
     * @return list<string>
     */
    private function tokens(string $value, string $sampleId, string $field): array
    {
        if (preg_match('//u', $value) !== 1) {
            throw new MetricException(
                sprintf("Sample '%s' %s must be valid UTF-8 for bertscore-like metric.", $sampleId, $field),
            );
        }

        $lowercased = mb_strtolower($value, 'UTF-8');

        $result = preg_match_all('/[[:alnum:]]+/u', $lowercased, $matches);
        if ($result === false) {
            throw new MetricException(
                sprintf("Sample '%s' %s could not be tokenized for bertscore-like metric.", $sampleId, $field),
            );
        }

        /** @var list<string> $tokens */
        $tokens = $matches[0];
        $tokenCount = count($tokens);

        if ($tokenCount > $this->maxTokens) {
            throw new MetricException(sprintf(
                "Sample '%s' %s has %d tokens; bertscore-like metric supports at most %d tokens per field.",
                $sampleId,
                $field,
                $tokenCount,
                $this->maxTokens,
            ));
        }

        return $tokens;
    }

    /**
     * @param  list<list<float>>  $left
     * @param  list<list<float>>  $right
     */
    private function averageBestSimilarity(array $left, array $right): float
    {
        $sum = 0.0;

        foreach ($left as $leftVector) {
            $best = 0.0;

            foreach ($right as $rightVector) {
                $best = max($best, self::clampScore($this->cosineSimilarity($leftVector, $rightVector)));
            }

            $sum += $best;
        }

        return $sum / count($left);
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            throw new MetricException('BERTScore-like embeddings must be non-empty vectors.');
        }

        if (count($a) !== count($b)) {
            throw new MetricException(
                sprintf(
                    'Cannot compute BERTScore-like cosine similarity on vectors of different dimensionality (%d vs %d).',
                    count($a),
                    count($b),
                ),
            );
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        foreach ($a as $i => $ai) {
            $bi = $b[$i];
            $dot += $ai * $bi;
            $normA += $ai * $ai;
            $normB += $bi * $bi;
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    private static function clampScore(float $score): float
    {
        return max(0.0, min(1.0, $score));
    }
}
