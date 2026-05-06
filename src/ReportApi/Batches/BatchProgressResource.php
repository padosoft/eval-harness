<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Batches;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BatchProgressResource extends JsonResource
{
    /**
     * @return array{id: string, status: string, sample_count: int, successes: int, failures: int, pending: int, ttl_seconds: int}
     */
    public function toArray(Request $request): array
    {
        /** @var array{id: string, status: string, sample_count: int, successes: int, failures: int, ttl_seconds: int} $payload */
        $payload = $this->resource;
        $pending = max(0, $payload['sample_count'] - $payload['successes'] - $payload['failures']);

        return [
            'id' => $payload['id'],
            'status' => $payload['status'],
            'sample_count' => $payload['sample_count'],
            'successes' => $payload['successes'],
            'failures' => $payload['failures'],
            'pending' => $pending,
            'ttl_seconds' => $payload['ttl_seconds'],
        ];
    }
}
