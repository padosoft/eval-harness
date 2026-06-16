<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\Retrieval\RankedRetrieval;

/**
 * answer-containment@k: 1.0 when the expected answer span appears
 * inside any of the top-k retrieved TEXTS, else 0.0.
 *
 * Operates on retrieved texts (not ids). `expected_output` MUST be a
 * non-empty string — the answer span to find. Comparison is
 * whitespace-normalized (internal runs collapsed, trimmed) and, by
 * default, case-insensitive; pass `caseSensitive: true` to require an
 * exact-case match.
 *
 * Alias `answer-containment-at-k` resolves via the container with zero
 * extra binding (auto-wired ConfigRepository; k defaults to config,
 * caseSensitive defaults to false). Override defaults by binding an
 * explicit instance under the alias.
 */
final class AnswerContainmentAtKMetric implements Metric
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ?int $k = null,
        private readonly bool $caseSensitive = false,
    ) {}

    public function name(): string
    {
        return 'answer-containment-at-k';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        if (! is_string($sample->expectedOutput) || trim($sample->expectedOutput) === '') {
            throw new MetricException(sprintf(
                "Sample '%s' expected_output must be a non-empty string (the answer span) for answer-containment-at-k metric.",
                $sample->id,
            ));
        }

        $ranked = RankedRetrieval::fromActualOutput($actualOutput, $sample->id);
        $k = $this->resolveK($sample);

        $needle = $this->normalize($sample->expectedOutput);

        $matchedRank = null;
        foreach ($ranked->topKTexts($k) as $index => $text) {
            $haystack = $this->normalize($text);
            if ($haystack !== '' && str_contains($haystack, $needle)) {
                $matchedRank = $index + 1;
                break;
            }
        }

        return new MetricScore($matchedRank !== null ? 1.0 : 0.0, [
            'k' => $k,
            'expected_span' => $sample->expectedOutput,
            'matched_rank' => $matchedRank,
        ]);
    }

    private function normalize(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $value) ?? $value;
        $collapsed = trim($collapsed);

        return $this->caseSensitive ? $collapsed : mb_strtolower($collapsed, 'UTF-8');
    }

    private function resolveK(DatasetSample $sample): int
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
