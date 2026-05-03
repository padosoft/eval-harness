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
    }

    public function test_rejects_unsupported_modes(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("Unsupported batch mode 'lazy-parallel'");

        new BatchOptions(mode: 'lazy-parallel');
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

    public function test_rejects_invalid_timeout(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('Batch timeout');

        new BatchOptions(timeoutSeconds: 0);
    }

    public function test_serial_mode_rejects_timeout(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage('does not use a timeout');

        new BatchOptions(timeoutSeconds: 30);
    }
}
