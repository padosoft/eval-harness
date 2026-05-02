<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Datasets;

use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;

/**
 * Intermediate value object emitted by {@see YamlDatasetLoader}.
 *
 * Holds the validated raw shape (name + sample list) before the
 * {@see DatasetBuilder} attaches the metric stack and produces a
 * fully-resolved {@see GoldenDataset}.
 *
 * Two-phase load is deliberate: the YAML schema knows nothing
 * about which metrics the caller will register, and metric
 * registration is a runtime concern (DI container, suggested
 * package availability). Keeping the parsed shape immutable +
 * separate keeps the loader pure and testable.
 */
final class ParsedDatasetDefinition
{
    /**
     * @param  list<DatasetSample>  $samples
     */
    public function __construct(
        public readonly string $name,
        public readonly array $samples,
        public readonly string $schemaVersion = DatasetSchema::VERSION,
    ) {
        if (! DatasetSchema::isSupported($schemaVersion)) {
            throw new DatasetSchemaException(
                sprintf(
                    "Parsed dataset '%s' uses unsupported schema version '%s'. Supported versions: %s.",
                    $name,
                    $schemaVersion,
                    implode(', ', DatasetSchema::SUPPORTED_VERSIONS),
                ),
            );
        }
    }
}
