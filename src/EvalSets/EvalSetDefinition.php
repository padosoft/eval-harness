<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\EvalSets;

use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Named group of registered datasets to run as one eval set.
 */
final class EvalSetDefinition
{
    public const SCHEMA_VERSION = 'eval-harness.eval-set.v1';

    public readonly string $name;

    /** @var list<string> */
    public readonly array $datasetNames;

    /**
     * @param  list<string>  $datasetNames
     */
    public function __construct(
        string $name,
        array $datasetNames,
        public readonly string $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ($name === '' || $name !== trim($name)) {
            throw new EvalRunException('Eval set name must be a non-empty string without leading or trailing whitespace.');
        }
        $this->name = $name;

        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new EvalRunException(sprintf(
                "Eval set '%s' uses unsupported schema version '%s'. Supported version: %s.",
                $name,
                $schemaVersion,
                self::SCHEMA_VERSION,
            ));
        }

        if ($datasetNames === []) {
            throw new EvalRunException(sprintf("Eval set '%s' must include at least one dataset.", $name));
        }

        if (! array_is_list($datasetNames)) {
            throw new EvalRunException(sprintf("Eval set '%s' dataset names must be a zero-based list.", $name));
        }

        $normalizedDatasetNames = [];
        $seen = [];
        foreach ($datasetNames as $index => $rawDatasetName) {
            if (! is_string($rawDatasetName)) {
                throw new EvalRunException(sprintf(
                    "Eval set '%s' dataset name at index %d must be a string; got %s.",
                    $name,
                    $index,
                    get_debug_type($rawDatasetName),
                ));
            }

            if ($rawDatasetName === '' || $rawDatasetName !== trim($rawDatasetName)) {
                throw new EvalRunException(sprintf(
                    "Eval set '%s' dataset name at index %d must be a non-empty string without leading or trailing whitespace.",
                    $name,
                    $index,
                ));
            }

            $key = self::datasetNameKey($rawDatasetName);
            if (isset($seen[$key])) {
                throw new EvalRunException(sprintf(
                    "Eval set '%s' contains duplicate dataset '%s'.",
                    $name,
                    $rawDatasetName,
                ));
            }

            $seen[$key] = true;
            $normalizedDatasetNames[] = $rawDatasetName;
        }

        $this->datasetNames = $normalizedDatasetNames;
    }

    /**
     * @return array{schema_version: string, name: string, datasets: list<string>}
     */
    public function toJson(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'name' => $this->name,
            'datasets' => $this->datasetNames,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromJson(array $payload): self
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        $name = $payload['name'] ?? null;
        $datasets = $payload['datasets'] ?? null;

        if (! is_string($schemaVersion) || ! is_string($name) || ! is_array($datasets)) {
            throw new EvalRunException('Eval set definition requires schema_version, name, and datasets fields.');
        }

        return new self(
            name: $name,
            datasetNames: self::datasetNamesFromPayload($datasets),
            schemaVersion: $schemaVersion,
        );
    }

    public static function datasetNameKey(string $datasetName): string
    {
        return sprintf('dataset:%d:%s', strlen($datasetName), $datasetName);
    }

    /**
     * @param  array<array-key, mixed>  $datasets
     * @return list<string>
     */
    private static function datasetNamesFromPayload(array $datasets): array
    {
        if (! array_is_list($datasets)) {
            throw new EvalRunException('Eval set definition datasets field must be a zero-based list.');
        }

        $datasetNames = [];
        foreach ($datasets as $index => $datasetName) {
            if (! is_string($datasetName)) {
                throw new EvalRunException(sprintf(
                    'Eval set definition dataset at index %d must be a string.',
                    $index,
                ));
            }

            $datasetNames[] = $datasetName;
        }

        return $datasetNames;
    }
}
