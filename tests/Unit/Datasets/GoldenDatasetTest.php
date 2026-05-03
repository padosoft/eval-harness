<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Datasets;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\GoldenDataset;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Padosoft\EvalHarness\Metrics\ExactMatchMetric;
use PHPUnit\Framework\TestCase;

final class GoldenDatasetTest extends TestCase
{
    public function test_constructor_rejects_unsupported_schema_version(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('unsupported schema version');

        new GoldenDataset(
            name: 'bad.schema',
            samples: [new DatasetSample(id: 's1', input: [], expectedOutput: 'x')],
            metrics: [new ExactMatchMetric],
            schemaVersion: 'eval-harness.dataset.v999',
        );
    }

    public function test_constructor_rejects_non_zero_based_samples(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('samples must be a zero-based list');

        new GoldenDataset(
            name: 'bad.samples',
            samples: [
                1 => new DatasetSample(id: 's1', input: [], expectedOutput: 'x'),
            ],
            metrics: [new ExactMatchMetric],
        );
    }
}
