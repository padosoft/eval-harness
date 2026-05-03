<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\EvalSets;

use Padosoft\EvalHarness\EvalSets\EvalSetDefinition;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use PHPUnit\Framework\TestCase;

final class EvalSetDefinitionTest extends TestCase
{
    public function test_definition_normalizes_name_and_dataset_names(): void
    {
        $definition = new EvalSetDefinition(' nightly ', [' rag.first ', '01', '1']);

        $this->assertSame('nightly', $definition->name);
        $this->assertSame(['rag.first', '01', '1'], $definition->datasetNames);
        $this->assertSame([
            'schema_version' => EvalSetDefinition::SCHEMA_VERSION,
            'name' => 'nightly',
            'datasets' => ['rag.first', '01', '1'],
        ], $definition->toJson());
    }

    public function test_definition_round_trips_from_json(): void
    {
        $definition = EvalSetDefinition::fromJson([
            'schema_version' => EvalSetDefinition::SCHEMA_VERSION,
            'name' => 'nightly',
            'datasets' => ['rag.first', 'rag.second'],
        ]);

        $this->assertSame('nightly', $definition->name);
        $this->assertSame(['rag.first', 'rag.second'], $definition->datasetNames);
    }

    public function test_definition_rejects_sparse_dataset_lists(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('zero-based list');

        new EvalSetDefinition('nightly', [1 => 'rag.first']);
    }

    public function test_definition_rejects_duplicate_dataset_names_after_normalization(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("duplicate dataset 'rag.first'");

        new EvalSetDefinition('nightly', ['rag.first', ' rag.first ']);
    }

    public function test_definition_rejects_non_string_dataset_names(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('must be a string');

        new EvalSetDefinition('nightly', ['rag.first', 42]);
    }
}
