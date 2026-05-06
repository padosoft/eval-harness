<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Diff;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Padosoft\EvalHarness\ReportApi\ReportArtifact;
use Padosoft\EvalHarness\ReportApi\ReportArtifactResource;

/**
 * Envelope shape for a report-diff payload.
 *
 * Augments the `ReportDiffComputer::compute()` result with the two
 * `ReportArtifact` metadata blocks so UI clients can label sides
 * without an extra round-trip.
 *
 * @property-read array{
 *     left: ReportArtifact,
 *     right: ReportArtifact,
 *     diff: array<string, mixed>,
 * } $resource
 */
final class ReportDiffResource extends JsonResource
{
    /**
     * @return array{
     *     left: array<string, mixed>,
     *     right: array<string, mixed>,
     *     delta: array<string, mixed>,
     * }
     */
    public function toArray(Request $request): array
    {
        $diff = $this->resource['diff'];

        return [
            'left' => [
                'artifact' => (new ReportArtifactResource($this->resource['left']))->toArray($request),
                'summary' => $diff['left']['summary'],
            ],
            'right' => [
                'artifact' => (new ReportArtifactResource($this->resource['right']))->toArray($request),
                'summary' => $diff['right']['summary'],
            ],
            'delta' => $diff['delta'],
        ];
    }
}
