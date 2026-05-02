<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Datasets;

/**
 * Single sample inside a {@see GoldenDataset}.
 *
 * - $id is unique within the dataset (the YAML loader enforces it).
 * - $input is an associative array passed verbatim to the
 *   system-under-test callable. Conventionally contains `question`
 *   for RAG datasets, but the harness is shape-agnostic — every key
 *   the SUT consumes is the caller's responsibility.
 * - $expectedOutput is the golden answer; metrics interpret it
 *   according to their own contract (string-equality, embedding,
 *   judge-prompt, etc.).
 * - $metadata is a free-form bag for tags / source citations /
 *   difficulty markers; never inspected by the harness itself.
 *
 * Constructor-promoted readonly properties; every field is required.
 */
final class DatasetSample
{
    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly array $input,
        public readonly mixed $expectedOutput,
        public readonly array $metadata = [],
    ) {}
}
