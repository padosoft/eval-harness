<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Contracts;

use JsonException;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\EvalRunException;

/**
 * Input-only payload passed to runner-style SUT invocations.
 *
 * SampleRunner implementations and callables whose first parameter is
 * typed as SampleInvocation invoke the application under test without
 * needing the golden answer or free-form metadata used by
 * metrics/reporting. This keeps the future queued payload smaller and
 * avoids leaking non-serializable expected-output or metadata values
 * into jobs.
 */
final class SampleInvocation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function __construct(
        public readonly string $id,
        public readonly array $input,
    ) {
        self::assertJsonQueueEncodable($input, $id);

        foreach ($input as $key => $value) {
            if (! is_string($key)) {
                throw new EvalRunException(
                    sprintf("SampleInvocation input key for sample '%s' must be a string; got %s.", $id, get_debug_type($key)),
                );
            }

            self::assertQueueSafeValue($value, sprintf('input.%s', $key), $id);
        }
    }

    public static function fromDatasetSample(DatasetSample $sample): self
    {
        return new self(
            id: $sample->id,
            input: $sample->input,
        );
    }

    private static function assertQueueSafeValue(mixed $value, string $path, string $sampleId): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $key => $nestedValue) {
                $nestedPath = is_string($key)
                    ? sprintf('%s.%s', $path, $key)
                    : sprintf('%s[%s]', $path, $key);

                self::assertQueueSafeValue($nestedValue, $nestedPath, $sampleId);
            }

            return;
        }

        throw new EvalRunException(
            sprintf(
                "SampleInvocation value at '%s' for sample '%s' must be queue-serializable (scalar, null, or array); got %s.",
                $path,
                $sampleId,
                get_debug_type($value),
            ),
        );
    }

    /**
     * Laravel queue payloads are JSON encoded. Probe the input before the
     * recursive type walk so cyclic arrays fail once instead of recursing
     * indefinitely while validating nested values.
     *
     * @param  array<string, mixed>  $input
     */
    private static function assertJsonQueueEncodable(array $input, string $sampleId): void
    {
        try {
            json_encode($input, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            if ($e->getCode() === JSON_ERROR_RECURSION) {
                throw new EvalRunException(
                    sprintf("SampleInvocation input for sample '%s' must not contain recursive arrays or objects.", $sampleId),
                    previous: $e,
                );
            }

            throw new EvalRunException(
                sprintf(
                    "SampleInvocation input for sample '%s' must be JSON-serializable for queue payloads: %s.",
                    $sampleId,
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }
    }
}
