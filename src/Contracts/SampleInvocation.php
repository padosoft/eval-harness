<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Contracts;

use Padosoft\EvalHarness\Datasets\DatasetSample;

/**
 * Input-only payload passed to a SampleRunner.
 *
 * Runners invoke the application under test; they do not need the
 * golden answer or free-form metadata used by metrics/reporting. This
 * keeps the future queued payload smaller and avoids leaking
 * non-serializable expected-output or metadata values into jobs.
 */
final class SampleInvocation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $id,
        public readonly array $input,
    ) {}

    public static function fromDatasetSample(DatasetSample $sample): self
    {
        return new self(
            id: $sample->id,
            input: $sample->input,
        );
    }
}
