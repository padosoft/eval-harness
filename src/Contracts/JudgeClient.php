<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Contracts;

use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Provider boundary for LLM judge-backed metrics.
 *
 * Implementations that can expose safe token/cost/latency metadata may
 * also implement {@see ProvidesUsageDetails}.
 */
interface JudgeClient
{
    /**
     * @throws MetricException when the provider fails or does not return
     *                         a usable message content string.
     */
    public function judge(string $prompt): string;
}
