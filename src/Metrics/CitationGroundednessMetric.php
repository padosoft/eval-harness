<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Baseline citation groundedness metric.
 *
 * Samples declare required citation strings in metadata.citations
 * (string or string list). The metric scores the fraction of required
 * citations present verbatim in the actual output. Report details
 * expose counts only, not the raw metadata citation strings.
 */
final class CitationGroundednessMetric implements Metric
{
    public function name(): string
    {
        return 'citation-groundedness';
    }

    public function score(DatasetSample $sample, string $actualOutput): MetricScore
    {
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
                'required_citation_count' => count($citations),
                'matched_citation_count' => count($matched),
                'missing_citation_count' => count($missing),
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
}
