<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Outputs;

use JsonException;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
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
    /**
     * @return array<string, string>
     */
    public function loadFile(string $path): array
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

    /**
     * @return array<string, string>
     */
    public function loadString(string $contents, string $source = 'inline saved outputs'): array
    {
        $decoded = $this->decode($contents, $source);
        if (! is_array($decoded)) {
            throw new EvalRunException(sprintf("Saved outputs in '%s' must be a JSON/YAML object or list.", $source));
        }

        $rawOutputs = array_key_exists('outputs', $decoded) ? $decoded['outputs'] : $decoded;
        if (! is_array($rawOutputs)) {
            throw new EvalRunException(sprintf("Saved outputs in '%s' must contain an outputs object or list.", $source));
        }

        return $this->isListShape($rawOutputs)
            ? $this->normalizeList($rawOutputs, $source)
            : $this->normalizeMap($rawOutputs, $source);
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
            return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            if ($this->looksLikeJsonPath($source)) {
                throw new EvalRunException(sprintf(
                    "Saved outputs file '%s' contains invalid JSON: %s.",
                    $source,
                    $jsonException->getMessage(),
                ), previous: $jsonException);
            }

            return $this->decodeYaml($contents, $source);
        }
    }

    private function decodeYaml(string $contents, string $source): mixed
    {
        try {
            return Yaml::parse($contents);
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
     * @return array<string, string>
     */
    private function normalizeList(array $rawOutputs, string $source): array
    {
        $outputs = [];
        foreach ($rawOutputs as $index => $entry) {
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

            $this->putOutput($outputs, $id, $actualOutput, $source);
        }

        return $outputs;
    }

    /**
     * @param  array<mixed>  $rawOutputs
     * @return array<string, string>
     */
    private function normalizeMap(array $rawOutputs, string $source): array
    {
        $outputs = [];
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

            $this->putOutput($outputs, $sampleId, $actualOutput, $source);
        }

        return $outputs;
    }

    /**
     * @param  array<mixed>  $rawOutputs
     */
    private function isListShape(array $rawOutputs): bool
    {
        if (! array_is_list($rawOutputs)) {
            return false;
        }

        foreach ($rawOutputs as $entry) {
            if (! is_array($entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $outputs
     */
    private function putOutput(array &$outputs, string $sampleId, string $actualOutput, string $source): void
    {
        if (array_key_exists($sampleId, $outputs)) {
            throw new EvalRunException(sprintf(
                "Duplicate saved output for sample '%s' in '%s'.",
                $sampleId,
                $source,
            ));
        }

        $outputs[$sampleId] = $actualOutput;
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
