<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Batches;

use Padosoft\EvalHarness\Batches\SerialBatch;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use PHPUnit\Framework\TestCase;

final class SerialBatchTest extends TestCase
{
    public function test_runs_samples_in_dataset_order_with_indexes(): void
    {
        $samples = [
            new DatasetSample(id: 'first', input: ['q' => 'a'], expectedOutput: 'A'),
            new DatasetSample(id: 'second', input: ['q' => 'b'], expectedOutput: 'B'),
        ];

        $seen = [];
        $outputs = (new SerialBatch)->run(
            $samples,
            static function (DatasetSample $sample, int $index) use (&$seen): string {
                $seen[] = [$sample->id, $index];

                return strtoupper((string) $sample->input['q']);
            },
        );

        $this->assertSame(['A', 'B'], $outputs);
        $this->assertSame([['first', 0], ['second', 1]], $seen);
    }

    public function test_rejects_non_string_outputs(): void
    {
        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("System-under-test for sample 's1' must return a string");

        (new SerialBatch)->run(
            [new DatasetSample(id: 's1', input: [], expectedOutput: 'x')],
            /** @phpstan-ignore-next-line deliberately wrong return type */
            static fn (): int => 42,
        );
    }
}
