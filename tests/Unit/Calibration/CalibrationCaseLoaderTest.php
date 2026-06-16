<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\Calibration;

use Padosoft\EvalHarness\Calibration\CalibrationCaseLoader;
use Padosoft\EvalHarness\Calibration\HumanLabel;
use Padosoft\EvalHarness\Exceptions\DatasetSchemaException;
use PHPUnit\Framework\TestCase;

final class CalibrationCaseLoaderTest extends TestCase
{
    private const VALID = <<<'YAML'
        schema_version: eval-harness.calibration.v1
        name: judge.calibration.fy2026
        cases:
          - id: c1
            input:
              question: "What is the capital of France?"
            expected: "Paris"
            actual: "The capital of France is Paris."
            human_verdict: pass
          - id: c2
            input:
              question: "What is the capital of France?"
            expected: "Paris"
            actual: "It is Berlin."
            human_verdict: fail
        YAML;

    public function test_loads_human_labels(): void
    {
        $labels = (new CalibrationCaseLoader)->loadString(self::VALID);

        $this->assertCount(2, $labels);
        $this->assertContainsOnlyInstancesOf(HumanLabel::class, $labels);

        $first = $labels[0];
        $this->assertSame('c1', $first->id);
        $this->assertSame(['question' => 'What is the capital of France?'], $first->input);
        $this->assertSame('Paris', $first->expected);
        $this->assertSame('The capital of France is Paris.', $first->actual);
        $this->assertSame('pass', $first->humanVerdict);
        $this->assertSame('fail', $labels[1]->humanVerdict);
    }

    public function test_duplicate_id_throws(): void
    {
        $yaml = <<<'YAML'
            cases:
              - id: dup
                input: {q: a}
                expected: "x"
                actual: "x"
                human_verdict: pass
              - id: dup
                input: {q: a}
                expected: "y"
                actual: "y"
                human_verdict: fail
            YAML;

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("Duplicate calibration case id 'dup'");
        (new CalibrationCaseLoader)->loadString($yaml);
    }

    public function test_invalid_verdict_throws(): void
    {
        $yaml = <<<'YAML'
            cases:
              - id: c1
                input: {q: a}
                expected: "x"
                actual: "x"
                human_verdict: maybe
            YAML;

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage('human_verdict');
        (new CalibrationCaseLoader)->loadString($yaml);
    }

    public function test_missing_cases_throws(): void
    {
        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("missing required list field 'cases'");
        (new CalibrationCaseLoader)->loadString("name: x\n");
    }

    public function test_non_string_expected_throws(): void
    {
        $yaml = <<<'YAML'
            cases:
              - id: c1
                input: {q: a}
                expected: 3
                actual: "x"
                human_verdict: pass
            YAML;

        $this->expectException(DatasetSchemaException::class);
        $this->expectExceptionMessage("field 'expected' must be a string");
        (new CalibrationCaseLoader)->loadString($yaml);
    }
}
