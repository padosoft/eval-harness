<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Batches;

use Padosoft\EvalHarness\Batches\BatchOptions;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use PHPUnit\Framework\TestCase;

final class BatchOptionsTest extends TestCase
{
    public function test_serial_defaults_are_valid(): void
    {
        $options = BatchOptions::serial();

        $this->assertSame(BatchOptions::MODE_SERIAL, $options->mode);
        $this->assertSame(1, $options->concurrency);
        $this->assertNull($options->queue);
        $this->assertNull($options->timeoutSeconds);
        $this->assertNull($options->waitTimeoutSeconds);
    }

    public function test_lazy_parallel_options_are_valid(): void
    {
        $options = BatchOptions::lazyParallel(
            concurrency: 4,
            queue: 'evals',
            timeoutSeconds: 30,
            waitTimeoutSeconds: 300,
            resultTtlSeconds: 900,
        );

        $this->assertSame(BatchOptions::MODE_LAZY_PARALLEL, $options->mode);
        $this->assertSame(4, $options->concurrency);
        $this->assertSame('evals', $options->queue);
        $this->assertSame(30, $options->timeoutSeconds);
        $this->assertSame(300, $options->waitTimeoutSeconds);
        $this->assertSame(900, $options->resultTtlSeconds);
    }

    public function test_queue_name_is_trimmed(): void
    {
        $options = BatchOptions::lazyParallel(queue: ' evals ');

        $this->assertSame('evals', $options->queue);
    }

    public function test_rejects_unsupported_modes(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Unsupported batch mode 'parallel'");

        new BatchOptions(mode: 'parallel');
    }

    public function test_serial_mode_requires_single_concurrency(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Serial batch mode requires concurrency 1');

        new BatchOptions(concurrency: 2);
    }

    public function test_serial_mode_rejects_queue_name(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('does not use a queue name');

        new BatchOptions(queue: 'evals');
    }

    public function test_rejects_blank_queue_name(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Batch queue name');

        BatchOptions::lazyParallel(queue: '   ');
    }

    public function test_rejects_invalid_timeout(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Queued sample timeout');

        new BatchOptions(timeoutSeconds: 0);
    }

    public function test_serial_mode_rejects_timeout(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('does not use a timeout');

        new BatchOptions(timeoutSeconds: 30);
    }

    public function test_serial_mode_rejects_wait_timeout(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('does not use a wait timeout');

        new BatchOptions(waitTimeoutSeconds: 30);
    }

    public function test_rejects_invalid_wait_timeout(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Batch wait timeout');

        BatchOptions::lazyParallel(waitTimeoutSeconds: 0);
    }

    public function test_rejects_invalid_result_ttl(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Batch result TTL');

        BatchOptions::lazyParallel(resultTtlSeconds: 0);
    }

    public function test_serial_mode_rejects_result_ttl(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('does not use a result TTL');

        new BatchOptions(resultTtlSeconds: 60);
    }

    public function test_lazy_parallel_supports_backpressure_and_profile_fields(): void
    {
        $options = BatchOptions::lazyParallel(
            concurrency: 8,
            queue: 'evals-nightly',
            timeoutSeconds: 60,
            waitTimeoutSeconds: 600,
            profile: 'nightly',
            chunkSize: 4,
            rateLimit: 30,
            rateWindowSeconds: 60,
            checkpointEvery: 25,
        );

        $this->assertSame('nightly', $options->profile);
        $this->assertSame(4, $options->chunkSize);
        $this->assertSame(4, $options->effectiveChunkSize());
        $this->assertSame(30, $options->rateLimit);
        $this->assertSame(60, $options->rateWindowSeconds);
        $this->assertSame(25, $options->checkpointEvery);
    }

    public function test_effective_chunk_size_falls_back_to_concurrency(): void
    {
        $options = BatchOptions::lazyParallel(concurrency: 6);

        $this->assertSame(6, $options->effectiveChunkSize());
    }

    public function test_rejects_invalid_chunk_size(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Batch chunk size must be null or greater than or equal to 1.');

        BatchOptions::lazyParallel(chunkSize: 0);
    }

    public function test_rejects_invalid_rate_limit(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Batch rate limit must be null or greater than or equal to 1.');

        BatchOptions::lazyParallel(rateLimit: 0);
    }

    public function test_rejects_invalid_rate_window_seconds(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Batch rate window seconds must be null or greater than or equal to 1.');

        BatchOptions::lazyParallel(rateWindowSeconds: 0);
    }

    public function test_rejects_invalid_checkpoint_interval(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Batch checkpoint interval must be null or greater than or equal to 1.');

        BatchOptions::lazyParallel(checkpointEvery: 0);
    }

    public function test_rejects_padded_profile_name(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Batch profile name must be null or a non-empty string without leading or trailing whitespace.');

        BatchOptions::lazyParallel(profile: ' ci ');
    }

    public function test_serial_mode_rejects_chunk_size(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Serial batch mode does not use a chunk size.');

        new BatchOptions(chunkSize: 1);
    }

    public function test_serial_mode_rejects_rate_limit(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Serial batch mode does not use a rate limit.');

        new BatchOptions(rateLimit: 1);
    }

    public function test_serial_mode_rejects_rate_window(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Serial batch mode does not use a rate window.');

        new BatchOptions(rateWindowSeconds: 30);
    }

    public function test_serial_mode_rejects_checkpoint_interval(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Serial batch mode does not use a checkpoint interval.');

        new BatchOptions(checkpointEvery: 10);
    }
}
