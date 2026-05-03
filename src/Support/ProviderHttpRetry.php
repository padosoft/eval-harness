<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Padosoft\EvalHarness\Exceptions\MetricException;

/**
 * Small retry loop for provider transports.
 *
 * Retries are intentionally narrow: Laravel HTTP connection failures,
 * HTTP 429, and 5xx responses. Non-retryable 4xx responses and
 * successful-but-malformed provider bodies still fail closed in the
 * caller's parser; unexpected programming/configuration throwables are
 * not retried or wrapped.
 */
final class ProviderHttpRetry
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function post(
        PendingRequest $request,
        ConfigRepository $config,
        string $endpoint,
        array $payload,
        string $operation,
    ): Response {
        $maxAttempts = RuntimeOptions::providerMaxAttempts($config);
        $sleepMilliseconds = RuntimeOptions::providerRetrySleepMilliseconds($config);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $request->post($endpoint, $payload);
            } catch (ConnectionException $e) {
                if ($attempt >= $maxAttempts) {
                    throw new MetricException(
                        sprintf(
                            '%s request failed before receiving a response after %d attempt(s): %s.',
                            $operation,
                            $maxAttempts,
                            $e->getMessage() !== '' ? $e->getMessage() : $e::class,
                        ),
                        previous: $e,
                    );
                }

                self::sleepBeforeRetry($sleepMilliseconds);

                continue;
            }

            if (! self::shouldRetryResponse($response) || $attempt >= $maxAttempts) {
                return $response;
            }

            self::sleepBeforeRetry($sleepMilliseconds);
        }

        throw new MetricException(sprintf('%s request failed before receiving a response.', $operation));
    }

    private static function shouldRetryResponse(Response $response): bool
    {
        $status = $response->status();

        return $status === 429 || $status >= 500;
    }

    private static function sleepBeforeRetry(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
