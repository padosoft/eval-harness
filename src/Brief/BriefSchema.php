<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Brief;

/**
 * Machine-readable contract identifier for the run briefing.
 *
 * The briefing is an artifact like the report and the comparison: written by
 * CI, read by a Web UI and by whatever pastes it into a coding agent. It
 * carries its own version so a consumer can tell a briefing from a report at a
 * glance, and so the shape can grow additively without a reader guessing.
 */
final class BriefSchema
{
    public const VERSION = 'eval-harness.brief.v1';
}
