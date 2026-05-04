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
     * @throws MetricException when the provider returns a failed HTTP
     *                         response or no usable message content string.
     * @throws \Throwable for unexpected request/configuration/programming
     *                    failures that should not be retried or wrapped.
     */
    public function judge(string $prompt): string;
}
