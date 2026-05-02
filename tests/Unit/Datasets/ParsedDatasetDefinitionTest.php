<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Datasets;

use Padosoft\EvalHarness\Datasets\ParsedDatasetDefinition;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use PHPUnit\Framework\TestCase;

final class ParsedDatasetDefinitionTest extends TestCase
{
    public function test_constructor_rejects_unsupported_schema_version(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('unsupported schema version');

        new ParsedDatasetDefinition(
            name: 'bad.schema',
            samples: [],
            schemaVersion: 'eval-harness.dataset.v999',
        );
    }
}
