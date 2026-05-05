<?php

declare(strict_types=1);

namespace Padosoft\EvalHarness\ReportApi;

/**
 * Version markers for the read-only report API envelope.
 *
 * `VERSION` is the envelope-level version that every payload carries
 * under `schema_version`. UI clients pin against this value to gate
 * shape compatibility. It only changes when an existing endpoint
 * breaks an existing field — additive endpoints (Macro 9+) keep this
 * stable.
 *
 * The per-endpoint discriminator constants (`SCHEMA_*`) emitted under
 * the `schema` field on each new payload let UI clients negotiate
 * shape on a per-endpoint basis without bumping the envelope version.
 */
final class ReportApiSchema
{
    public const VERSION = 'eval-harness.report-api.v1';

    public const SCHEMA_DIFF = 'eval-harness.report-api.v1.diff';

    public const SCHEMA_ADVERSARIAL_MANIFESTS = 'eval-harness.report-api.v1.adversarial-manifests';

    public const SCHEMA_ADVERSARIAL_MANIFEST = 'eval-harness.report-api.v1.adversarial-manifest';
}
