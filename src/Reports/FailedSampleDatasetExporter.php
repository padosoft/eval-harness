<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Reports;

use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Datasets\DatasetSchema;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Symfony\Component\Yaml\Yaml;

/**
 * Exports failed eval samples as a reloadable dataset YAML seed.
 */
final class FailedSampleDatasetExporter
{
    private const FAILURE_METADATA_KEY = 'eval_harness';

    private const FAILURE_PROMOTION_KEY = 'promoted_failure';

    public function failedSampleCount(EvalReport $report): int
    {
        return count($this->failedMetricNamesBySample($report));
    }

    public function exportYaml(EvalReport $report, ?string $datasetName = null): ?string
    {
        $failedMetricNames = $this->failedMetricNamesBySample($report);
        if ($failedMetricNames === []) {
            return null;
        }

        $samples = [];
        foreach ($report->sampleResults as $result) {
            $metricNames = $failedMetricNames[$result->sample->id] ?? null;
            if ($metricNames === null) {
                continue;
            }

            $samples[] = $this->promotedSample(
                sample: $result->sample,
                sourceDataset: $report->datasetName,
                failedMetricNames: $metricNames,
            );
        }

        if ($samples === []) {
            return null;
        }

        return Yaml::dump([
            DatasetSchema::FIELD => DatasetSchema::VERSION,
            'name' => $datasetName ?? $report->datasetName.'.failures',
            'samples' => $samples,
        ], 6, 2);
    }

    /**
     * @return array<string, list<string>>
     */
    private function failedMetricNamesBySample(EvalReport $report): array
    {
        $failed = [];

        foreach ($report->sampleResults as $result) {
            foreach ($result->metricScores as $metricName => $score) {
                if ($score->score >= 0.5) {
                    continue;
                }

                $failed[$result->sample->id][$metricName] = true;
            }
        }

        foreach ($report->failures as $failure) {
            $failed[$failure->sampleId][$failure->metricName] = true;
        }

        $normalized = [];
        foreach ($failed as $sampleId => $metricNames) {
            $names = array_keys($metricNames);
            sort($names, SORT_STRING);
            $normalized[$sampleId] = $names;
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $failedMetricNames
     * @return array{id: string, input: array<string, mixed>, expected_output: mixed, metadata: array<string, mixed>}
     */
    private function promotedSample(DatasetSample $sample, string $sourceDataset, array $failedMetricNames): array
    {
        $metadata = $this->normalizeAssociativeArray(
            $sample->metadata,
            sprintf("sample '%s'.metadata", $sample->id),
        );

        $existingHarnessMetadata = $metadata[self::FAILURE_METADATA_KEY] ?? [];
        if ($existingHarnessMetadata !== [] && ! is_array($existingHarnessMetadata)) {
            throw new EvalRunException(sprintf(
                "Cannot promote sample '%s' because metadata.%s must be an object when present.",
                $sample->id,
                self::FAILURE_METADATA_KEY,
            ));
        }

        if (array_key_exists(self::FAILURE_PROMOTION_KEY, $existingHarnessMetadata)) {
            throw new EvalRunException(sprintf(
                "Cannot promote sample '%s' because metadata.%s.%s is reserved for failure promotion metadata.",
                $sample->id,
                self::FAILURE_METADATA_KEY,
                self::FAILURE_PROMOTION_KEY,
            ));
        }

        /** @var array<string, mixed> $harnessMetadata */
        $harnessMetadata = $existingHarnessMetadata;
        $harnessMetadata[self::FAILURE_PROMOTION_KEY] = [
            'source_dataset' => $sourceDataset,
            'failed_metrics' => $failedMetricNames,
        ];
        $metadata[self::FAILURE_METADATA_KEY] = $harnessMetadata;

        return [
            'id' => $sample->id,
            'input' => $this->normalizeAssociativeArray(
                $sample->input,
                sprintf("sample '%s'.input", $sample->id),
            ),
            'expected_output' => $this->normalizeValue(
                $sample->expectedOutput,
                sprintf("sample '%s'.expected_output", $sample->id),
            ),
            'metadata' => $metadata,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private function normalizeAssociativeArray(array $value, string $path): array
    {
        if ($value !== [] && array_is_list($value)) {
            throw new EvalRunException(sprintf('Cannot promote failures because %s must be an associative array.', $path));
        }

        /** @var array<string, mixed> $normalized */
        $normalized = $this->normalizeValue($value, $path);

        return $normalized;
    }

    private function normalizeValue(mixed $value, string $path): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            if (is_nan($value) || is_infinite($value)) {
                throw new EvalRunException(sprintf('Cannot promote failures because %s must be a finite number.', $path));
            }

            return $value;
        }

        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new EvalRunException(sprintf('Cannot promote failures because %s must be valid UTF-8.', $path));
            }

            return $value;
        }

        if (! is_array($value)) {
            throw new EvalRunException(sprintf('Cannot promote failures because %s contains a non-serializable %s value.', $path, get_debug_type($value)));
        }

        $isList = array_is_list($value);
        $normalized = [];
        foreach ($value as $key => $entry) {
            if ($isList) {
                $normalized[] = $this->normalizeValue($entry, sprintf('%s[%d]', $path, $key));

                continue;
            }

            if (! is_string($key) || $key === '') {
                throw new EvalRunException(sprintf('Cannot promote failures because %s must use non-empty string keys.', $path));
            }

            if (preg_match('//u', $key) !== 1) {
                throw new EvalRunException(sprintf('Cannot promote failures because %s contains a non-UTF-8 key.', $path));
            }

            $normalized[$key] = $this->normalizeValue($entry, sprintf('%s.%s', $path, $key));
        }

        return $normalized;
    }
}
