<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Trend;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DatasetTrendResource extends JsonResource
{
    /**
     * @return array{dataset: string, limit: int, count: int, points: list<array<string, mixed>>}
     */
    public function toArray(Request $request): array
    {
        /** @var array{dataset: string, limit: int, points: list<array<string, mixed>>} $payload */
        $payload = $this->resource;

        return [
            'dataset' => $payload['dataset'],
            'limit' => $payload['limit'],
            'count' => count($payload['points']),
            'points' => $payload['points'],
        ];
    }
}
