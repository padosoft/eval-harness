<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Batches;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class BatchLiveResource extends JsonResource
{
    /**
     * @return array{batches: list<array{id: string, expires_at: int}>}
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, int> $live */
        $live = $this->resource;

        $batches = [];
        foreach ($live as $batchId => $expiresAt) {
            $batches[] = ['id' => $batchId, 'expires_at' => $expiresAt];
        }

        return ['batches' => $batches];
    }
}
