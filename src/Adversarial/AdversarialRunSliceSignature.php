<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Adversarial;

/**
 * Builds the compatibility key shared by baseline lookup and retention.
 *
 * @internal
 */
final class AdversarialRunSliceSignature
{
    public static function fromEntry(AdversarialRunManifestEntry $entry): string
    {
        return serialize([
            'metrics' => self::metricSignature($entry),
            'adversarial' => self::adversarialSliceSignature($entry),
        ]);
    }

    /**
     * @return list<string>
     */
    private static function metricSignature(AdversarialRunManifestEntry $entry): array
    {
        $names = array_keys($entry->metrics);
        sort($names);

        return $names;
    }

    /**
     * @return array{total_samples: int, categories: list<array{category: string, sample_count: int}>}
     */
    private static function adversarialSliceSignature(AdversarialRunManifestEntry $entry): array
    {
        $categories = [];
        $rawCategories = $entry->adversarial['categories'] ?? [];
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
            static fn (array $left, array $right): int => strcmp($left['category'], $right['category']),
        );

        $totalSamples = $entry->adversarial['total_samples'] ?? 0;

        return [
            'total_samples' => is_int($totalSamples) ? $totalSamples : 0,
            'categories' => $categories,
        ];
    }
}
