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
        $this->assertSame(['[doc:1]'], $score->details['matched_citations']);
        $this->assertSame(['[doc:2]'], $score->details['missing_citations']);
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
}
