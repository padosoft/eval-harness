<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Calibration;

use Padosoft\EvalHarness\Datasets\YamlDatasetLoader;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Strict-schema YAML loader for judge-calibration cases.
 *
 * Expected file shape:
 *
 * ```yaml
 * schema_version: eval-harness.calibration.v1 # optional
 * name: judge.calibration.fy2026
 * cases:
 *   - id: c1
 *     input:
 *       question: "What is the capital of France?"
 *     expected: "Paris"
 *     actual: "The capital of France is Paris."
 *     human_verdict: pass
 * ```
 *
 * Required keys: `cases` (non-empty list). Per-case required keys:
 * `id` (string, unique), `input` (assoc array), `expected` (string),
 * `actual` (string), `human_verdict` ('pass'|'fail').
 *
 * Mirrors {@see YamlDatasetLoader}'s
 * strictness; every failure throws {@see DatasetSchemaException} with
 * the offending case index when applicable.
 */
final class CalibrationCaseLoader
{
    private const SUPPORTED_VERSION = 'eval-harness.calibration.v1';

    private const VERDICTS = ['pass', 'fail'];

    /**
     * @return list<HumanLabel>
     */
    public function loadFile(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DatasetSchemaException(
                sprintf('Calibration YAML file is missing or unreadable: %s', $path),
            );
        }

        return $this->loadString((string) file_get_contents($path));
    }

    /**
     * @return list<HumanLabel>
     */
    public function loadString(string $yaml): array
    {
        try {
            $decoded = Yaml::parse($yaml);
        } catch (ParseException $e) {
            throw new DatasetSchemaException(
                sprintf('Calibration YAML is not valid YAML: %s', $e->getMessage()),
                previous: $e,
            );
        }

        return $this->validate(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<mixed>  $decoded
     * @return list<HumanLabel>
     */
    private function validate(array $decoded): array
    {
        $this->assertSchemaVersion($decoded);

        if (! isset($decoded['cases']) || ! is_array($decoded['cases'])) {
            throw new DatasetSchemaException(
                "Calibration YAML missing required list field 'cases'.",
            );
        }

        $cases = $decoded['cases'];
        if ($cases === []) {
            throw new DatasetSchemaException(
                'Calibration YAML must declare at least one case.',
            );
        }

        $labels = [];
        $seenIds = [];

        foreach ($cases as $index => $case) {
            if (! is_array($case)) {
                throw new DatasetSchemaException(
                    sprintf('Calibration case at index %d is not an associative array.', $index),
                );
            }

            $labels[] = $this->validateCase($case, $index, $seenIds);
        }

        return $labels;
    }

    /**
     * @param  array<mixed>  $decoded
     */
    private function assertSchemaVersion(array $decoded): void
    {
        if (! array_key_exists('schema_version', $decoded)) {
            return;
        }

        $version = $decoded['schema_version'];
        if (! is_string($version) || $version === '') {
            throw new DatasetSchemaException(
                "Calibration YAML field 'schema_version' must be a non-empty string.",
            );
        }

        if ($version !== self::SUPPORTED_VERSION) {
            throw new DatasetSchemaException(sprintf(
                "Calibration YAML field 'schema_version' has unsupported value '%s'. Supported: %s.",
                $version,
                self::SUPPORTED_VERSION,
            ));
        }
    }

    /**
     * @param  array<mixed>  $case
     * @param  array<string, true>  $seenIds  in/out reference for duplicate detection.
     */
    private function validateCase(array $case, int $index, array &$seenIds): HumanLabel
    {
        if (! isset($case['id']) || ! is_string($case['id']) || $case['id'] === '') {
            throw new DatasetSchemaException(
                sprintf("Calibration case at index %d missing required string field 'id'.", $index),
            );
        }

        $id = $case['id'];

        if (isset($seenIds[$id])) {
            throw new DatasetSchemaException(
                sprintf("Duplicate calibration case id '%s' at index %d.", $id, $index),
            );
        }
        $seenIds[$id] = true;

        if (! array_key_exists('input', $case) || ! is_array($case['input']) || ! $this->isAssociativeOrEmpty($case['input'])) {
            throw new DatasetSchemaException(
                sprintf("Calibration case '%s' (index %d) field 'input' must be an associative array.", $id, $index),
            );
        }

        $expected = $case['expected'] ?? null;
        if (! is_string($expected)) {
            throw new DatasetSchemaException(
                sprintf("Calibration case '%s' (index %d) field 'expected' must be a string.", $id, $index),
            );
        }

        $actual = $case['actual'] ?? null;
        if (! is_string($actual)) {
            throw new DatasetSchemaException(
                sprintf("Calibration case '%s' (index %d) field 'actual' must be a string.", $id, $index),
            );
        }

        $verdict = $case['human_verdict'] ?? null;
        if (! is_string($verdict) || ! in_array($verdict, self::VERDICTS, true)) {
            throw new DatasetSchemaException(sprintf(
                "Calibration case '%s' (index %d) field 'human_verdict' must be one of: %s.",
                $id,
                $index,
                implode(', ', self::VERDICTS),
            ));
        }

        /** @var array<string, mixed> $input */
        $input = $case['input'];

        return new HumanLabel(
            id: $id,
            input: $input,
            expected: $expected,
            actual: $actual,
            humanVerdict: $verdict,
        );
    }

    /**
     * @param  array<mixed>  $array
     */
    private function isAssociativeOrEmpty(array $array): bool
    {
        if ($array === []) {
            return true;
        }

        return ! array_is_list($array);
    }
}
