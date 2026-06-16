<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Online;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OnlineTrendResource extends JsonResource
{
    /**
     * @return array{dataset: string, limit: int, count: int, threshold: float, points: list<array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        /** @var array{dataset: string, limit: int, threshold: float, points: list<array<string, mixed>>} $payload */
        $payload = $this->resource;

        return [
            'dataset' => $payload['dataset'],
            'limit' => $payload['limit'],
            'count' => count($payload['points']),
            'threshold' => $payload['threshold'],
            'points' => $payload['points'],
        ];
    }
}
