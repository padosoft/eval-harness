<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Contracts;

use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Provider boundary for embedding-backed metrics.
 *
 * Implementations may call OpenAI-compatible endpoints, Laravel AI
 * providers, or deterministic fakes. They must preserve input order
 * and return one numeric vector per requested text.
 */
interface EmbeddingClient
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>>
     *
     * @throws MetricException when the provider fails or returns an
     *                         unusable embedding payload.
     */
    public function embedMany(array $texts): array;
}
