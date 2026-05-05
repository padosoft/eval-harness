<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Adversarial;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifest;

/**
 * Envelope shape for an adversarial manifest show endpoint.
 *
 * Renders the parsed `AdversarialRunManifest` as plain associative
 * arrays so HTTP clients see the same shape the manifest file on disk
 * carries: the resource calls `AdversarialRunManifest::toJson()` and
 * remaps the on-disk `manifest` key to a UI-friendly `name`, plus two
 * derived fields (`runs_count`, `latest_run_id`) so a client does not
 * have to scan the runs array. Per-run rows pass through unmodified
 * from `AdversarialRunManifestEntry::toJson()` (already wrapped in
 * `toJson()` on the parent manifest).
 *
 * @property-read AdversarialRunManifest $resource
 */
final class ManifestResource extends JsonResource
{
    /**
     * @return array{
     *     schema_version: string,
     *     name: string,
     *     updated_at: float,
     *     runs_count: int,
     *     latest_run_id: string|null,
     *     runs: list<array<string, mixed>>,
     * }
     */
    public function toArray(Request $request): array
    {
        $manifest = $this->resource;
        $payload = $manifest->toJson();
        $latest = $manifest->latest();

        return [
            'schema_version' => $payload['schema_version'],
            'name' => $payload['manifest'],
            'updated_at' => $payload['updated_at'],
            'runs_count' => count($payload['runs']),
            'latest_run_id' => $latest?->runId,
            'runs' => $payload['runs'],
        ];
    }
}
