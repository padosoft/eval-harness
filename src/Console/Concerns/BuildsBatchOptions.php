<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Console\Concerns;

use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Exceptions\EvalRunException;

trait BuildsBatchOptions
{
    private function batchOptions(): BatchOptions
    {
        $batch = $this->option('batch');
        $mode = is_string($batch) && $batch !== '' ? $batch : BatchOptions::MODE_SERIAL;
        $queue = $this->option('queue');

        return new BatchOptions(
            mode: $mode,
            concurrency: $this->positiveIntegerOption('concurrency', 1),
            queue: is_string($queue) && $queue !== '' ? $queue : null,
            timeoutSeconds: $this->nullablePositiveIntegerOption('timeout'),
            waitTimeoutSeconds: $this->nullablePositiveIntegerOption('batch-timeout'),
        );
    }

    private function positiveIntegerOption(string $name, int $default): int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return $default;
        }

        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            throw new EvalRunException(sprintf('The --%s option must be a positive integer.', $name));
        }

        return (int) $value;
    }

    private function nullablePositiveIntegerOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || ! ctype_digit($value) || (int) $value < 1) {
            throw new EvalRunException(sprintf('The --%s option must be a positive integer.', $name));
        }

        return (int) $value;
    }
}
