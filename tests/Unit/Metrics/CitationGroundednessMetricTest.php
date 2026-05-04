<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Metrics;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\MetricException;
use Padosoft\EvalHarness\Metrics\CitationGroundednessMetric;
use PHPUnit\Framework\TestCase;

final class CitationGroundednessMetricTest extends TestCase
{
    public function test_scores_fraction_of_required_citations_present(): void
    {
        $score = (new CitationGroundednessMetric)->score(
            new DatasetSample(
                id: 's1',
                input: [],
                expectedOutput: 'unused',
                metadata: ['citations' => ['[doc:1]', '[doc:2]']],
            ),
            'Answer grounded in [doc:1].',
        );

        $this->assertSame(0.5, $score->score);
        $this->assertSame('citations', $score->details['mode']);
        $this->assertSame(2, $score->details['required_citation_count']);
        $this->assertSame(1, $score->details['matched_citation_count']);
        $this->assertSame(1, $score->details['missing_citation_count']);
        $this->assertArrayNotHasKey('required_citations', $score->details);
        $this->assertArrayNotHasKey('matched_citations', $score->details);
        $this->assertArrayNotHasKey('missing_citations', $score->details);
    }

    public function test_accepts_single_citation_string(): void
    {
        $score = (new CitationGroundednessMetric)->score(
            new DatasetSample(
                id: 's1',
                input: [],
                expectedOutput: 'unused',
                metadata: ['citations' => '[policy]'],
            ),
            'See [policy].',
        );

        $this->assertSame(1.0, $score->score);
    }

    public function test_missing_citations_metadata_throws(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('metadata.citations must contain at least one citation string');

        (new CitationGroundednessMetric)->score(
            new DatasetSample(id: 's1', input: [], expectedOutput: 'unused'),
            'No citations.',
        );
    }

    public function test_scores_evidence_spans_by_requiring_citation_and_quote(): void
    {
        $score = (new CitationGroundednessMetric)->score(
            new DatasetSample(
                id: 's1',
                input: [],
                expectedOutput: 'unused',
                metadata: [
                    'citation_evidence' => [
                        [
                            'citation' => '[doc:1]',
                            'quote' => 'Refunds are available within 30 days.',
                        ],
                        [
                            'citation' => '[doc:2]',
                            'quote' => 'Items must be unused.',
                        ],
                    ],
                ],
            ),
            'Refunds are available within 30 days. [doc:1] See [doc:2].',
        );

        $this->assertSame(0.5, $score->score);
        $this->assertSame('citation_evidence', $score->details['mode']);
        $this->assertSame(2, $score->details['required_evidence_count']);
        $this->assertSame(1, $score->details['matched_evidence_count']);
        $this->assertSame(0, $score->details['missing_citation_count']);
        $this->assertSame(1, $score->details['missing_quote_count']);
        $this->assertArrayNotHasKey('required_quotes', $score->details);
        $this->assertArrayNotHasKey('matched_quotes', $score->details);
        $this->assertArrayNotHasKey('missing_quotes', $score->details);
    }

    public function test_evidence_quote_matching_normalizes_case_and_whitespace(): void
    {
        $score = (new CitationGroundednessMetric)->score(
            new DatasetSample(
                id: 's1',
                input: [],
                expectedOutput: 'unused',
                metadata: [
                    'citation_evidence' => [
                        [
                            'citation' => '[policy]',
                            'quote' => 'Refunds are available within 30 days.',
                        ],
                    ],
                ],
            ),
            "REFUNDS   are available\nwithin 30 days. [policy]",
        );

        $this->assertSame(1.0, $score->score);
    }

    public function test_evidence_requires_citation_even_when_quote_matches(): void
    {
        $score = (new CitationGroundednessMetric)->score(
            new DatasetSample(
                id: 's1',
                input: [],
                expectedOutput: 'unused',
                metadata: [
                    'citation_evidence' => [
                        [
                            'citation' => '[policy]',
                            'quote' => 'Refunds are available within 30 days.',
                        ],
                    ],
                ],
            ),
            'Refunds are available within 30 days.',
        );

        $this->assertSame(0.0, $score->score);
        $this->assertSame(1, $score->details['missing_citation_count']);
        $this->assertSame(0, $score->details['missing_quote_count']);
    }

    public function test_evidence_accepts_alternate_quotes(): void
    {
        $score = (new CitationGroundednessMetric)->score(
            new DatasetSample(
                id: 's1',
                input: [],
                expectedOutput: 'unused',
                metadata: [
                    'citation_evidence' => [
                        [
                            'citation' => '[policy]',
                            'quotes' => ['unused items only', 'returnable if unopened'],
                        ],
                    ],
                ],
            ),
            'The item is returnable if unopened. [policy]',
        );

        $this->assertSame(1.0, $score->score);
    }

    public function test_evidence_quotes_list_rejects_non_string_entries(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('metadata.citation_evidence[0].quotes[1] must be a non-empty string');

        (new CitationGroundednessMetric)->score(
            new DatasetSample(
                id: 's1',
                input: [],
                expectedOutput: 'unused',
                metadata: [
                    'citation_evidence' => [
                        [
                            'citation' => '[policy]',
                            'quotes' => ['valid quote', 123],
                        ],
                    ],
                ],
            ),
            'valid quote [policy]',
        );
    }

    public function test_empty_evidence_list_throws(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('metadata.citation_evidence must be a non-empty list');

        (new CitationGroundednessMetric)->score(
            new DatasetSample(
                id: 's1',
                input: [],
                expectedOutput: 'unused',
                metadata: ['citation_evidence' => []],
            ),
            'No citations.',
        );
    }

    public function test_evidence_span_without_quote_throws(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('must contain a non-empty quote string or quotes list');

        (new CitationGroundednessMetric)->score(
            new DatasetSample(
                id: 's1',
                input: [],
                expectedOutput: 'unused',
                metadata: ['citation_evidence' => [['citation' => '[doc:1]']]],
            ),
            'No citations.',
        );
    }

    public function test_evidence_span_without_citation_throws(): void
    {
        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('.citation must be a non-empty string');

        (new CitationGroundednessMetric)->score(
            new DatasetSample(
                id: 's1',
                input: [],
                expectedOutput: 'unused',
                metadata: ['citation_evidence' => [['quote' => 'Some evidence.']]],
            ),
            'Some evidence.',
        );
    }
}
