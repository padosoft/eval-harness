<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Outputs;

use JsonException;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads precomputed sample outputs for standalone assertion runs.
 *
 * Accepted shapes:
 *   - {"outputs": {"sample-id": "actual output"}}
 *   - {"outputs": [{"id": "sample-id", "actual_output": "..."}]}
 *   - The same shapes as a YAML document.
 */
final class SavedOutputsLoader
{
    public function loadFile(string $path): SavedOutputs
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new EvalRunException(sprintf("Saved outputs file '%s' is not readable.", $path));
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new EvalRunException(sprintf("Saved outputs file '%s' could not be read.", $path));
        }

        return $this->loadString($contents, $path);
    }

    public function loadString(string $contents, string $source = 'inline saved outputs'): SavedOutputs
    {
        $decoded = $this->decode($contents, $source);
        if (! $decoded instanceof stdClass && ! is_array($decoded)) {
            throw new EvalRunException(sprintf("Saved outputs in '%s' must be a JSON/YAML object or list.", $source));
        }

        $rawOutputs = $this->rawOutputs($decoded, $source);
        if (! $rawOutputs instanceof stdClass && ! is_array($rawOutputs)) {
            throw new EvalRunException(sprintf("Saved outputs in '%s' must contain an outputs object or list.", $source));
        }

        if ($rawOutputs instanceof stdClass) {
            return $this->normalizeMap(get_object_vars($rawOutputs), $source);
        }

        if ($this->isListShape($rawOutputs, $source)) {
            return $this->normalizeList($rawOutputs, $source);
        }

        return $this->normalizeMap($rawOutputs, $source);
    }

    /**
     * @param  stdClass|array<array-key, mixed>  $decoded
     */
    private function rawOutputs(stdClass|array $decoded, string $source): mixed
    {
        if ($decoded instanceof stdClass) {
            $properties = get_object_vars($decoded);

            if (! array_key_exists('outputs', $properties)) {
                throw new EvalRunException(sprintf("Saved outputs in '%s' must contain an outputs field.", $source));
            }

            return $properties['outputs'];
        }

        throw new EvalRunException(sprintf("Saved outputs in '%s' must contain an outputs field.", $source));
    }

    private function decode(string $contents, string $source): mixed
    {
        if (trim($contents) === '') {
            throw new EvalRunException(sprintf("Saved outputs in '%s' must not be empty.", $source));
        }

        if ($this->looksLikeYamlPath($source)) {
            return $this->decodeYaml($contents, $source);
        }

        try {
            return json_decode($contents, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            if ($this->looksLikeJsonPath($source)) {
                throw new EvalRunException(sprintf(
                    "Saved outputs file '%s' contains invalid JSON: %s.",
                    $source,
                    $jsonException->getMessage(),
                ), previous: $jsonException);
            }

            try {
                return $this->decodeYaml($contents, $source);
            } catch (EvalRunException $yamlException) {
                throw new EvalRunException(sprintf(
                    "Saved outputs file '%s' could not be parsed as JSON or YAML. JSON error: %s. YAML error: %s.",
                    $source,
                    $jsonException->getMessage(),
                    $yamlException->getMessage(),
                ), previous: $yamlException);
            }
        }
    }

    private function decodeYaml(string $contents, string $source): mixed
    {
        try {
            return Yaml::parse($contents, Yaml::PARSE_OBJECT_FOR_MAP);
        } catch (ParseException $e) {
            throw new EvalRunException(sprintf(
                "Saved outputs file '%s' contains invalid YAML: %s.",
                $source,
                $e->getMessage(),
            ), previous: $e);
        }
    }

    /**
     * @param  array<mixed>  $rawOutputs
     */
    private function normalizeList(array $rawOutputs, string $source): SavedOutputs
    {
        $entries = [];
        foreach ($rawOutputs as $index => $entry) {
            if ($entry instanceof stdClass) {
                $entry = get_object_vars($entry);
            }

            if (! is_array($entry)) {
                throw new EvalRunException(sprintf(
                    "Saved outputs entry at index %d in '%s' must be an object with id and actual_output.",
                    $index,
                    $source,
                ));
            }

            $id = $entry['id'] ?? null;
            if (! is_string($id) || $id === '') {
                throw new EvalRunException(sprintf(
                    "Saved outputs entry at index %d in '%s' must contain a non-empty string id.",
                    $index,
                    $source,
                ));
            }

            $actualOutput = $entry['actual_output'] ?? null;
            if (! is_string($actualOutput)) {
                throw new EvalRunException(sprintf(
                    "Saved output for sample '%s' in '%s' must contain a string actual_output.",
                    $id,
                    $source,
                ));
            }

            $entries[] = ['id' => $id, 'actual_output' => $actualOutput];
        }

        return $this->savedOutputs($entries, $source);
    }

    /**
     * @param  array<mixed>  $rawOutputs
     */
    private function normalizeMap(array $rawOutputs, string $source): SavedOutputs
    {
        $entries = [];
        foreach ($rawOutputs as $id => $actualOutput) {
            $sampleId = (string) $id;
            if ($sampleId === '') {
                throw new EvalRunException(sprintf("Saved outputs in '%s' contain an empty sample id.", $source));
            }

            if (! is_string($actualOutput)) {
                throw new EvalRunException(sprintf(
                    "Saved output for sample '%s' in '%s' must be a string; got %s.",
                    $sampleId,
                    $source,
                    get_debug_type($actualOutput),
                ));
            }

            $entries[] = ['id' => $sampleId, 'actual_output' => $actualOutput];
        }

        return $this->savedOutputs($entries, $source);
    }

    /**
     * @param  array<mixed>  $rawOutputs
     */
    private function isListShape(array $rawOutputs, string $source): bool
    {
        if (! array_is_list($rawOutputs)) {
            return false;
        }

        foreach ($rawOutputs as $index => $entry) {
            if ($entry instanceof stdClass || is_array($entry)) {
                continue;
            }

            throw new EvalRunException(sprintf(
                "Saved outputs entry at index %d in '%s' must be an object with id and actual_output.",
                $index,
                $source,
            ));
        }

        return true;
    }

    /**
     * @param  list<array{id: string, actual_output: string}>  $entries
     */
    private function savedOutputs(array $entries, string $source): SavedOutputs
    {
        try {
            return new SavedOutputs($entries);
        } catch (EvalRunException $e) {
            throw new EvalRunException(sprintf("%s Source: '%s'.", $e->getMessage(), $source), previous: $e);
        }
    }

    private function looksLikeYamlPath(string $source): bool
    {
        return (bool) preg_match('/\.ya?ml$/i', $source);
    }

    private function looksLikeJsonPath(string $source): bool
    {
        return (bool) preg_match('/\.json$/i', $source);
    }
}
