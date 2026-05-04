<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Citation groundedness metric.
 *
 * Baseline mode uses metadata.citations (string or string list) and
 * scores citation-marker presence. Advanced mode uses
 * metadata.citation_evidence, a list of citation+quote evidence spans,
 * and scores only spans where both citation marker and quote text are
 * present in the actual output.
 *
 * Report details expose counts only, not raw citation or quote strings.
 */
final class CitationGroundednessMetric implements Metric
{
    public function name(): string
    {
        return 'citation-groundedness';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
        $evidenceSpans = $this->evidenceSpansFor($sample);
        if ($evidenceSpans !== null) {
            return $this->scoreEvidenceSpans($sample, $actualOutput, $evidenceSpans);
        }

        $citations = $this->citationsFor($sample);
        $matched = [];
        $missing = [];

        foreach ($citations as $citation) {
            if (str_contains($actualOutput, $citation)) {
                $matched[] = $citation;
            } else {
                $missing[] = $citation;
            }
        }

        return new MetricScore(
            score: count($matched) / count($citations),
            details: [
                'mode' => 'citations',
                'required_citation_count' => count($citations),
                'matched_citation_count' => count($matched),
                'missing_citation_count' => count($missing),
            ],
        );
    }

    /**
     * @param  list<array{citation: string, quotes: list<string>}>  $evidenceSpans
     */
    private function scoreEvidenceSpans(DatasetSample $sample, string $actualOutput, array $evidenceSpans): MetricScore
    {
        $normalizedActual = $this->normalizeQuoteText($actualOutput, $sample->id, 'actual_output');
        $matched = 0;
        $missingCitation = 0;
        $missingQuote = 0;

        foreach ($evidenceSpans as $span) {
            $citationPresent = str_contains($actualOutput, $span['citation']);
            $quotePresent = false;

            foreach ($span['quotes'] as $quote) {
                if ($this->containsNormalizedQuote($normalizedActual, $quote, $sample->id)) {
                    $quotePresent = true;
                    break;
                }
            }

            if ($citationPresent && $quotePresent) {
                $matched++;

                continue;
            }

            if (! $citationPresent) {
                $missingCitation++;
            }

            if (! $quotePresent) {
                $missingQuote++;
            }
        }

        return new MetricScore(
            score: $matched / count($evidenceSpans),
            details: [
                'mode' => 'citation_evidence',
                'required_evidence_count' => count($evidenceSpans),
                'matched_evidence_count' => $matched,
                'missing_citation_count' => $missingCitation,
                'missing_quote_count' => $missingQuote,
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function citationsFor(DatasetSample $sample): array
    {
        $raw = $sample->metadata['citations'] ?? null;
        $citations = [];

        if (is_string($raw)) {
            $citation = trim($raw);
            if ($citation !== '') {
                $citations[] = $citation;
            }
        }

        if (is_array($raw)) {
            foreach ($raw as $value) {
                if (! is_string($value)) {
                    continue;
                }

                $citation = trim($value);
                if ($citation !== '') {
                    $citations[] = $citation;
                }
            }
        }

        $citations = array_values(array_unique($citations));
        if ($citations === []) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' metadata.citations must contain at least one citation string for citation-groundedness metric.",
                    $sample->id,
                ),
            );
        }

        return $citations;
    }

    /**
     * @return list<array{citation: string, quotes: list<string>}>|null
     */
    private function evidenceSpansFor(DatasetSample $sample): ?array
    {
        if (! array_key_exists('citation_evidence', $sample->metadata)) {
            return null;
        }

        $raw = $sample->metadata['citation_evidence'];
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' metadata.citation_evidence must be a non-empty list of evidence spans.",
                    $sample->id,
                ),
            );
        }

        $spans = [];
        foreach ($raw as $index => $value) {
            if (! is_array($value)) {
                throw new MetricException(
                    sprintf(
                        "Sample '%s' metadata.citation_evidence[%d] must be an object with citation and quote fields.",
                        $sample->id,
                        $index,
                    ),
                );
            }

            $citation = $value['citation'] ?? null;
            if (! is_string($citation) || trim($citation) === '') {
                throw new MetricException(
                    sprintf(
                        "Sample '%s' metadata.citation_evidence[%d].citation must be a non-empty string.",
                        $sample->id,
                        $index,
                    ),
                );
            }

            $quotes = $this->quotesFor($sample, $value, $index);
            $spans[] = [
                'citation' => trim($citation),
                'quotes' => $quotes,
            ];
        }

        return $spans;
    }

    /**
     * @param  array<mixed>  $span
     * @return list<string>
     */
    private function quotesFor(DatasetSample $sample, array $span, int $index): array
    {
        $quotes = [];
        if (array_key_exists('quote', $span)) {
            $single = $span['quote'];
            if (! is_string($single) || trim($single) === '') {
                throw new MetricException(
                    sprintf(
                        "Sample '%s' metadata.citation_evidence[%d].quote must be a non-empty string.",
                        $sample->id,
                        $index,
                    ),
                );
            }

            $quotes[] = trim($single);
        }

        if (array_key_exists('quotes', $span)) {
            $many = $span['quotes'];
            if (! is_array($many) || ! array_is_list($many)) {
                throw new MetricException(
                    sprintf(
                        "Sample '%s' metadata.citation_evidence[%d].quotes must be a list of non-empty strings.",
                        $sample->id,
                        $index,
                    ),
                );
            }

            foreach ($many as $quoteIndex => $quote) {
                if (! is_string($quote) || trim($quote) === '') {
                    throw new MetricException(
                        sprintf(
                            "Sample '%s' metadata.citation_evidence[%d].quotes[%d] must be a non-empty string.",
                            $sample->id,
                            $index,
                            $quoteIndex,
                        ),
                    );
                }

                $quotes[] = trim($quote);
            }
        }

        $quotes = array_values(array_unique($quotes));
        if ($quotes === []) {
            throw new MetricException(
                sprintf(
                    "Sample '%s' metadata.citation_evidence[%d] must contain a non-empty quote string or quotes list.",
                    $sample->id,
                    $index,
                ),
            );
        }

        return $quotes;
    }

    private function containsNormalizedQuote(string $normalizedActualOutput, string $quote, string $sampleId): bool
    {
        $needle = $this->normalizeQuoteText($quote, $sampleId, 'metadata.citation_evidence.quote');

        return str_contains($normalizedActualOutput, $needle);
    }

    private function normalizeQuoteText(string $value, string $sampleId, string $field): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new MetricException(
                sprintf("Sample '%s' %s must be valid UTF-8 for citation-groundedness metric.", $sampleId, $field),
            );
        }

        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($value), 'UTF-8'));
        if (! is_string($normalized)) {
            throw new MetricException(
                sprintf("Sample '%s' %s could not be normalized for citation-groundedness metric.", $sampleId, $field),
            );
        }

        return $normalized;
    }
}
