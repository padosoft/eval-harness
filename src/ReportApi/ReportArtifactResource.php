<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read ReportArtifact $resource
 */
final class ReportArtifactResource extends JsonResource
{
    /**
     * @return array{id: string, path: string, format: string, size_bytes: int, last_modified: int}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'path' => $this->resource->path,
            'format' => $this->resource->format,
            'size_bytes' => $this->resource->sizeBytes,
            'last_modified' => $this->resource->lastModified,
        ];
    }
}
