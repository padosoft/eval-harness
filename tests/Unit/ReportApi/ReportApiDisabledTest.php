<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Tests\Unit\ReportApi;

use Padosoft\EvalHarness\Tests\TestCase;

final class ReportApiDisabledTest extends TestCase
{
    public function test_report_api_routes_are_disabled_by_default(): void
    {
        $this->getJson('/eval-harness/api/reports')->assertNotFound();
    }
}
