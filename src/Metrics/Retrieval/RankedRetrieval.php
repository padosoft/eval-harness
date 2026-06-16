<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Metrics\Retrieval;

use JsonException;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Domain-agnostic parser + value object for retrieval-ranking metrics.
 *
 * `actualOutput` JSON (emitted by the host app after running its
 * retriever) is one of:
 *   - object: {"retrieved": [ {"id": "doc-7", "text": "..."}, ... ]}
 *   - bare array: ["doc-7", "doc-3"] or [{"id": "...", "text": "..."}]
 *
 * Each retrieved entry is rank-ordered (rank 1 first). Bare strings are
 * treated as ids with empty text. Duplicate ids are de-duplicated
 * keeping the first (best) rank; order is preserved.
 *
 * `expected_output` (the relevant ground truth) is one of:
 *   - flat list of relevant ids:  ["doc-3", "doc-9"]
 *   - JSON-encoded flat list:     '["doc-3","doc-9"]'
 *   - graded gain map:            {"doc-3": 3, "doc-9": 1}
 */
final class RankedRetrieval
{
    /**
     * @param  list<string>  $ids  rank-ordered, de-duplicated
     * @param  list<string>  $texts  index-aligned with $ids ('' when absent)
     */
    private function __construct(
        private readonly array $ids,
        private readonly array $texts,
    ) {}

    public static function fromActualOutput(string $json, string $sampleId): self
    {
        $trimmed = trim($json);

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new MetricException(
                sprintf("Sample '%s' retrieval actualOutput is not valid JSON: %s.", $sampleId, $e->getMessage()),
                previous: $e,
            );
        }

        $list = self::extractRetrievedList($decoded, $sampleId);

        $ids = [];
        $texts = [];
        $seen = [];

        foreach ($list as $entry) {
            [$id, $text] = self::normalizeEntry($entry, $sampleId);

            if (isset($seen[$id])) {
                continue; // keep best (first) rank
            }

            $seen[$id] = true;
            $ids[] = $id;
            $texts[] = $text;
        }

        return new self($ids, $texts);
    }

    /**
     * @return list<string>
     */
    public static function relevantIdsFromExpected(mixed $expected, string $sampleId): array
    {
        return array_keys(self::relevanceGainsFromExpected($expected, $sampleId));
    }

    /**
     * @return array<string, float>
     */
    public static function relevanceGainsFromExpected(mixed $expected, string $sampleId): array
    {
        if (is_string($expected)) {
            try {
                /** @var mixed $expected */
                $expected = json_decode($expected, true, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new MetricException(
                    sprintf("Sample '%s' retrieval expected_output is not valid JSON: %s.", $sampleId, $e->getMessage()),
                    previous: $e,
                );
            }
        }

        if (! is_array($expected) || $expected === []) {
            throw new MetricException(
                sprintf("Sample '%s' retrieval expected_output must list at least one relevant id.", $sampleId),
            );
        }

        $gains = [];

        if (array_is_list($expected)) {
            foreach ($expected as $id) {
                if (! is_string($id) || $id === '') {
                    throw new MetricException(
                        sprintf("Sample '%s' retrieval expected_output ids must be non-empty strings.", $sampleId),
                    );
                }
                $gains[$id] = 1.0;
            }

            return $gains;
        }

        /** @var array<array-key, mixed> $expected */
        foreach ($expected as $id => $gain) {
            $id = (string) $id;
            if ($id === '' || ! is_numeric($gain)) {
                throw new MetricException(
                    sprintf("Sample '%s' retrieval expected_output gain map must be id => numeric gain.", $sampleId),
                );
            }
            $gains[$id] = (float) $gain;
        }

        return $gains;
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return $this->ids;
    }

    /**
     * @return list<string>
     */
    public function texts(): array
    {
        return $this->texts;
    }

    /**
     * @return list<string>
     */
    public function topKIds(int $k): array
    {
        return array_slice($this->ids, 0, max(0, $k));
    }

    /**
     * @return list<string>
     */
    public function topKTexts(int $k): array
    {
        return array_slice($this->texts, 0, max(0, $k));
    }

    public function count(): int
    {
        return count($this->ids);
    }

    /**
     * @return list<mixed>
     */
    private static function extractRetrievedList(mixed $decoded, string $sampleId): array
    {
        if (is_array($decoded) && array_is_list($decoded)) {
            return $decoded;
        }

        if (is_array($decoded) && array_key_exists('retrieved', $decoded)) {
            $retrieved = $decoded['retrieved'];
            if (is_array($retrieved) && array_is_list($retrieved)) {
                return $retrieved;
            }
        }

        throw new MetricException(
            sprintf("Sample '%s' retrieval actualOutput must be a JSON array or an object with a 'retrieved' array.", $sampleId),
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function normalizeEntry(mixed $entry, string $sampleId): array
    {
        if (is_string($entry) && $entry !== '') {
            return [$entry, ''];
        }

        if (is_array($entry) && isset($entry['id']) && is_string($entry['id']) && $entry['id'] !== '') {
            $text = $entry['text'] ?? '';

            return [$entry['id'], is_string($text) ? $text : ''];
        }

        throw new MetricException(
            sprintf("Sample '%s' retrieval entry must be a non-empty id string or an object with a non-empty 'id'.", $sampleId),
        );
    }
}
