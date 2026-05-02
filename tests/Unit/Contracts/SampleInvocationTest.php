<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Contracts;

use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use PHPUnit\Framework\TestCase;
use stdClass;

final class SampleInvocationTest extends TestCase
{
    public function test_from_dataset_sample_keeps_only_id_and_input(): void
    {
        $sample = new DatasetSample(
            id: 's1',
            input: ['question' => 'What?'],
            expectedOutput: new stdClass,
            metadata: ['object' => new stdClass],
        );

        $invocation = SampleInvocation::fromDatasetSample($sample);

        $this->assertSame('s1', $invocation->id);
        $this->assertSame(['question' => 'What?'], $invocation->input);
        $this->assertFalse(property_exists($invocation, 'expectedOutput'));
        $this->assertFalse(property_exists($invocation, 'metadata'));
    }
}
