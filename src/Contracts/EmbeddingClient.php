<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Contracts;

use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Provider boundary for embedding-backed metrics.
 *
 * Implementations may call OpenAI-compatible endpoints, Laravel AI
 * providers, or deterministic fakes. They must preserve input order
 * and return one numeric vector per requested text. Implementations
 * that can expose safe token/cost/latency metadata may also implement
 * {@see ProvidesUsageDetails}.
 */
interface EmbeddingClient
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws MetricException when the provider returns a failed HTTP
     *                         response or an unusable embedding payload.
     * @throws \Throwable for unexpected request/configuration/programming
     *                    failures that should not be retried or wrapped.
     */
    public function embedMany(array $texts): array;
}
