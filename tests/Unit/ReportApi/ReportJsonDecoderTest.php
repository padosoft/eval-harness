<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi;

use Padosoft\EvalHarness\ReportApi\ReportJsonDecoder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ReportJsonDecoderTest extends TestCase
{
    public function test_malformed_json_throws_unprocessable_entity(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Report JSON artifact is malformed.');

        (new ReportJsonDecoder)->decodeObject('{"broken":');
    }

    public function test_non_object_json_throws_unprocessable_entity(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessage('Report JSON artifact must decode to an object.');

        (new ReportJsonDecoder)->decodeObject('[{"schema_version":"eval-harness.report.v1"}]');
    }
}
