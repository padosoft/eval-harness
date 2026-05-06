<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi;

use JsonException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ReportJsonDecoder
{
    /**
     * @return array<string, mixed>
     */
    public function decodeObject(string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnprocessableEntityHttpException('Report JSON artifact is malformed.', $e);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new UnprocessableEntityHttpException('Report JSON artifact must decode to an object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
