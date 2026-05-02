<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Reports;

/**
 * Machine-readable report contract identifiers.
 *
 * The JSON report is consumed by CI gates now and by a separate Web UI
 * package later, so every payload carries an explicit schema version.
 */
final class ReportSchema
{
    public const VERSION = 'eval-harness.report.v1';
}
