<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Adversarial;

use Padosoft\EvalHarness\Reports\EvalReport;

/**
 * Builds the compatibility key shared by baseline lookup and retention.
 *
 * @internal
 */
final class AdversarialRunSliceSignature
{
    public static function fromEntry(AdversarialRunManifestEntry $entry): string
    {
        return serialize(self::components(
            reportSchemaVersion: $entry->reportSchemaVersion,
            datasetName: $entry->datasetName,
            metricNames: array_keys($entry->metrics),
            adversarial: $entry->adversarial,
        ));
    }

    public static function fromReport(EvalReport $report): string
    {
        return serialize(self::components(
            reportSchemaVersion: $report->schemaVersion,
            datasetName: $report->datasetName,
            metricNames: $report->metricNames(),
            adversarial: $report->adversarialSummary(),
        ));
    }

    /**
     * @param  list<string>  $metricNames
     * @param  array<string, mixed>  $adversarial
     * @return array{report_schema_version: string, dataset: string, metrics: list<string>, adversarial: array{total_samples: int, categories: list<array{category: string, sample_count: int}>}}
     */
    private static function components(
        string $reportSchemaVersion,
        string $datasetName,
        array $metricNames,
        array $adversarial,
    ): array {
        sort($metricNames, SORT_STRING);

        return [
            'report_schema_version' => $reportSchemaVersion,
            'dataset' => $datasetName,
            'metrics' => $metricNames,
            'adversarial' => self::adversarialSliceSignature($adversarial),
        ];
    }

    /**
     * @param  array<string, mixed>  $adversarial
     * @return array{total_samples: int, categories: list<array{category: string, sample_count: int}>}
     */
    private static function adversarialSliceSignature(array $adversarial): array
    {
        $categories = [];
        $rawCategories = $adversarial['categories'] ?? [];
        if (is_array($rawCategories) && array_is_list($rawCategories)) {
            foreach ($rawCategories as $category) {
                if (! is_array($category)) {
                    continue;
                }

                $name = $category['category'] ?? null;
                $sampleCount = $category['sample_count'] ?? null;
                if (is_string($name) && is_int($sampleCount)) {
                    $categories[] = [
                        'category' => $name,
                        'sample_count' => $sampleCount,
                    ];
                }
            }
        }

        usort(
            $categories,
            static function (array $left, array $right): int {
                $byCategory = strcmp($left['category'], $right['category']);
                if ($byCategory !== 0) {
                    return $byCategory;
                }

                return $left['sample_count'] <=> $right['sample_count'];
            },
        );

        $totalSamples = $adversarial['total_samples'] ?? 0;

        return [
            'total_samples' => is_int($totalSamples) ? $totalSamples : 0,
            'categories' => $categories,
        ];
    }
}
