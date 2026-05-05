<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi\Adversarial;

use Illuminate\Http\Request;
use Padosoft\EvalHarness\Adversarial\AdversarialRunManifest;

/**
 * Small seam around ManifestResource so controller tests can verify
 * request pass-through without adding request-derived test fields to
 * the public payload.
 */
class ManifestResourceFactory
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(AdversarialRunManifest $manifest, Request $request): array
    {
        return (new ManifestResource($manifest))->toArray($request);
    }
}
