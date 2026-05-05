<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Adversarial;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifest;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifestEntry;

/**
 * Envelope shape for an adversarial manifest show endpoint.
 *
 * Renders the parsed `AdversarialRunManifest` as plain associative
 * arrays so HTTP clients see the same shape the manifest file on disk
 * carries (delegates to `AdversarialRunManifest::toJson()` for the
 * top-level fields and `AdversarialRunManifestEntry::toJson()` per
 * run). Adds derived `latest_run_id` for UI convenience so a client
 * does not have to scan the runs array.
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
        $latest = $manifest->latest();

        return [
            'schema_version' => $manifest->schemaVersion,
            'name' => $manifest->name,
            'updated_at' => $manifest->updatedAt,
            'runs_count' => count($manifest->runs),
            'latest_run_id' => $latest?->runId,
            'runs' => array_map(
                static fn (AdversarialRunManifestEntry $entry): array => $entry->toJson(),
                $manifest->runs,
            ),
        ];
    }
}
