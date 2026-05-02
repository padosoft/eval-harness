<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Datasets;

use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Strict-schema YAML loader for golden datasets.
 *
 * Expected file shape:
 *
 * ```yaml
 * name: rag.factuality.fy2026
 * samples:
 *   - id: sample-1
 *     input:
 *       question: "What is the capital of France?"
 *     expected_output: "Paris"
 *     metadata:
 *       tags: [geography, easy]
 *   - id: sample-2
 *     ...
 * ```
 *
 * Required keys: `name` (string), `samples` (non-empty list).
 * Per-sample required keys: `id` (string, unique), `input` (assoc
 * array), `expected_output` (any). `metadata` is optional.
 *
 * Every validation failure throws {@see DatasetSchemaException}
 * with a human-actionable message including the offending sample
 * index when applicable.
 */
final class YamlDatasetLoader
{
    public function loadFile(string $path): ParsedDatasetDefinition
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DatasetSchemaException(
                sprintf('Dataset YAML file is missing or unreadable: %s', $path),
            );
        }

        $raw = (string) file_get_contents($path);

        return $this->loadString($raw);
    }

    public function loadString(string $yaml): ParsedDatasetDefinition
    {
        try {
            $decoded = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new DatasetSchemaException(
                sprintf('Dataset YAML is not valid YAML: %s', $e->getMessage()),
                previous: $e,
            );
        }

        return $this->validate(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<mixed>  $decoded
     */
    private function validate(array $decoded): ParsedDatasetDefinition
    {
        if (! isset($decoded['name']) || ! is_string($decoded['name']) || $decoded['name'] === '') {
            throw new DatasetSchemaException(
                "Dataset YAML missing required string field 'name'.",
            );
        }

        if (! isset($decoded['samples']) || ! is_array($decoded['samples'])) {
            throw new DatasetSchemaException(
                "Dataset YAML missing required list field 'samples'.",
            );
        }

        $samples = $decoded['samples'];
        if ($samples === []) {
            throw new DatasetSchemaException(
                'Dataset YAML must declare at least one sample.',
            );
        }

        $parsedSamples = [];
        $seenIds = [];

        foreach ($samples as $index => $sample) {
            if (! is_array($sample)) {
                throw new DatasetSchemaException(
                    sprintf('Sample at index %d is not an associative array.', $index),
                );
            }

            $parsedSamples[] = $this->validateSample($sample, $index, $seenIds);
        }

        return new ParsedDatasetDefinition(
            name: $decoded['name'],
            samples: $parsedSamples,
        );
    }

    /**
     * @param  array<mixed>  $sample
     * @param  array<string, true>  $seenIds  in/out reference for duplicate detection.
     */
    private function validateSample(array $sample, int $index, array &$seenIds): DatasetSample
    {
        if (! isset($sample['id']) || ! is_string($sample['id']) || $sample['id'] === '') {
            throw new DatasetSchemaException(
                sprintf("Sample at index %d missing required string field 'id'.", $index),
            );
        }

        $id = $sample['id'];

        if (isset($seenIds[$id])) {
            throw new DatasetSchemaException(
                sprintf("Duplicate sample id '%s' at index %d.", $id, $index),
            );
        }
        $seenIds[$id] = true;

        if (! array_key_exists('input', $sample) || ! is_array($sample['input'])) {
            throw new DatasetSchemaException(
                sprintf("Sample '%s' (index %d) missing required associative-array field 'input'.", $id, $index),
            );
        }

        if (! self::isAssociativeOrEmpty($sample['input'])) {
            throw new DatasetSchemaException(
                sprintf(
                    "Sample '%s' (index %d) field 'input' must be an associative array (got a YAML list).",
                    $id,
                    $index,
                ),
            );
        }

        if (! array_key_exists('expected_output', $sample)) {
            throw new DatasetSchemaException(
                sprintf("Sample '%s' (index %d) missing required field 'expected_output'.", $id, $index),
            );
        }

        $metadata = [];
        if (array_key_exists('metadata', $sample)) {
            if (! is_array($sample['metadata'])) {
                throw new DatasetSchemaException(
                    sprintf("Sample '%s' (index %d) field 'metadata' must be an associative array.", $id, $index),
                );
            }
            if (! self::isAssociativeOrEmpty($sample['metadata'])) {
                throw new DatasetSchemaException(
                    sprintf(
                        "Sample '%s' (index %d) field 'metadata' must be an associative array (got a YAML list).",
                        $id,
                        $index,
                    ),
                );
            }
            $metadata = $sample['metadata'];
        }

        /** @var array<string, mixed> $input */
        $input = $sample['input'];

        /** @var array<string, mixed> $metadata */
        return new DatasetSample(
            id: $id,
            input: $input,
            expectedOutput: $sample['expected_output'],
            metadata: $metadata,
        );
    }

    /**
     * An array is "associative" if it has at least one non-integer
     * key OR is empty. Symfony YAML maps `{a: 1, b: 2}` and
     * `key: {...}` to associative arrays in PHP, but lists like
     * `[1, 2, 3]` end up as zero-indexed sequential arrays which
     * look like an array but aren't a map.
     *
     * Empty arrays are treated as valid associative-or-empty so a
     * sample legitimately declaring an empty `input: {}` doesn't
     * trip the check — `array_is_list([])` returns `true`, so we
     * special-case it.
     *
     * @param  array<mixed>  $array
     */
    private static function isAssociativeOrEmpty(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return ! array_is_list($array);
    }
}
