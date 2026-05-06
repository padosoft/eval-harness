# Eval Harness UI - Specification

This document defines the expected shape of a future
`padosoft/eval-harness-ui` package. It is a contract and product
specification only; the headless `padosoft/eval-harness` package must not
bundle UI routes, controllers, views, JavaScript, or CSS assets.

## 1. Mission And Scope

`padosoft/eval-harness-ui` should provide a read-only admin console for
teams that already run `padosoft/eval-harness` in a Laravel application.
The UI consumes only the public Report API introduced by eval-harness and
delegates all authentication, authorization, tenant scoping, and deployment
policy to the host application.

Primary goals:

- Show stored eval reports without operators opening raw JSON artifacts.
- Make regression risk visible through report comparisons and dataset trend
  charts.
- Expose adversarial run history and live lazy-parallel batch progress.
- Keep the UI package optional and replaceable.
- Avoid write operations against eval-harness until a future API version
  explicitly defines mutation contracts.

Non-goals:

- No bundled replacement for the host application's admin shell.
- No direct access to eval-harness internals or storage paths.
- No dataset editing, run triggering, threshold editing, or queue control in
  the first UI releases.
- No hard dependency on Laravel Horizon.

## 2. Architecture

Recommended package shape:

| Area | Recommendation |
| --- | --- |
| Composer package | `padosoft/eval-harness-ui` |
| Backend dependency | `padosoft/eval-harness:^1.2` |
| Frontend stack | Inertia.js + Vue 3 + Tailwind CSS |
| Alternative stack | Livewire 3 for teams without SPA tooling |
| Asset build | Laravel Vite plugin with publishable built assets |
| Data access | HTTP calls to eval-harness Report API only |
| Mount strategy | Host-configured route group under an admin prefix |

The UI package should ship a service provider that registers routes only
when explicitly enabled:

```php
// config/eval-harness-ui.php
return [
    'enabled' => env('EVAL_HARNESS_UI_ENABLED', false),
    'prefix' => env('EVAL_HARNESS_UI_PREFIX', 'admin/eval-harness'),
    'middleware' => ['web', 'auth', 'can:eval-harness.viewer'],
    'api_base' => env('EVAL_HARNESS_API_BASE', '/admin/eval-harness/api'),
];
```

The browser UI should call API endpoints through the same Laravel
application origin. For multi-app deployments, the UI package can allow a
fully qualified API base URL, but same-origin should remain the default.

### Internal Boundaries

The UI package must not import classes such as report repositories, batch
registries, manifest repositories, or config internals from
`padosoft/eval-harness`. Its supported integration surface is:

- Config published by the UI package itself.
- Host-provided middleware and gates.
- HTTP JSON and CSV endpoints from the eval-harness Report API.
- Static schema names emitted by endpoint payloads.

## 3. Auth Integration

The host application owns access control. The UI package should document a
minimal gate:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('eval-harness.viewer', function ($user): bool {
    return $user->can('viewEvalHarness');
});
```

Recommended patterns:

| Scenario | Pattern |
| --- | --- |
| Single admin app | `web`, `auth`, `can:eval-harness.viewer` |
| Multi-tenant admin | Tenant middleware before the viewer gate |
| Embedded dashboard | Signed route or short-lived API token |
| Internal ops console | SSO middleware plus viewer gate |

Optional future mutation gates should be named separately, for example
`eval-harness.admin`, so read-only deployments do not accidentally grant
write access.

### Tenant Scope

If the host app is multi-tenant, tenant resolution should happen before the
UI controller renders. The UI should then pass a tenant context header to
the Report API only when the host app has configured one:

```http
X-Eval-Harness-Tenant: acme
```

The base package does not define tenant behavior. A host-specific middleware
may translate this header into scoped report disks or prefixes.

## 4. Sections And Screens

The UI package should start with seven screens. Each screen must degrade
gracefully when an endpoint is disabled, returns 404, or returns 503 because
underlying report storage is unavailable.

### 4.1 Dashboard

Purpose: give an operator the current eval health in one screen.

Primary content:

- Total report count.
- Latest macro F1 score.
- Active batch count.
- Last adversarial run status.
- Trend mini-chart for the top three datasets.

Endpoints:

| Endpoint | Use |
| --- | --- |
| `GET /reports` | Latest report cards and report count |
| `GET /batches/live` | Active batch count |
| `GET /adversarial/manifests` | Last adversarial manifest summary |
| `GET /datasets/{name}/trend?limit=30` | Mini trend chart |

Wireframe:

```text
+------------------------------------------------------------------+
| Eval Harness                                                     |
| [Reports 128] [Latest F1 0.923] [Live Batches 2] [Adv OK]        |
+------------------------------+-----------------------------------+
| Dataset Trends              | Latest Reports                    |
| rag.faq       ____/--       | rag.faq      0.923   pass         |
| support.bot   __/---        | support.bot  0.887   warn         |
| safety.red    _/----        | safety.red   0.951   pass         |
+------------------------------+-----------------------------------+
| Active Batches                                                   |
| batch_01 [########------] 64/100  running                        |
+------------------------------------------------------------------+
```

### 4.2 Reports List

Purpose: browse stored report artifacts and find a run quickly.

Columns:

- Dataset name.
- Report id.
- Format.
- Schema version.
- Macro F1.
- Total samples.
- Finished at.
- Size.

Filters:

- Dataset name.
- Date range.
- Format.
- Schema version.
- Min macro F1.

Endpoint:

| Endpoint | Use |
| --- | --- |
| `GET /reports` | List JSON and Markdown report artifacts |

Wireframe:

```text
+------------------------------------------------------------------+
| Reports [dataset: ______] [format: all v] [date: last 30d v]     |
+----------------+----------+-------+----------+----------+--------+
| Dataset        | Macro F1 | Rows  | Finished | Format   | Action |
+----------------+----------+-------+----------+----------+--------+
| rag.faq        | 0.923    | 120   | 10:31    | json     | Open   |
| support.bot    | 0.887    | 240   | 09:18    | json     | Open   |
+----------------+----------+-------+----------+----------+--------+
```

### 4.3 Report Detail

Purpose: inspect one run without reading raw JSON.

Tabs:

- Summary.
- Cohorts.
- Histograms.
- Failures.
- Raw JSON.

Endpoints:

| Endpoint | Use |
| --- | --- |
| `GET /reports/{id}` | Metadata and raw artifact detail |
| `GET /reports/{id}/cohorts` | Cohort summaries |
| `GET /reports/{id}/histograms` | Score distribution buckets |
| `GET /reports/{id}/rows.csv` | CSV sample row export |
| `GET /reports/{id}/download` | Original artifact download |

Wireframe:

```text
+------------------------------------------------------------------+
| Report rag.faq / 2026-05-06 10:31                 [Download]     |
| Macro F1 0.923  Samples 120  Failures 4  Schema v1               |
+------------------------------------------------------------------+
| Summary | Cohorts | Histograms | Failures | Raw JSON             |
+------------------------------------------------------------------+
| Correctness 0.941  Faithfulness 0.902  Safety 0.938              |
| Cohort: billing   pass_rate 0.91   samples 34                    |
+------------------------------------------------------------------+
```

### 4.4 Compare Two Reports

Purpose: show whether a run improved or regressed against another run.

Interaction:

- Pick a left report and a right report.
- Shortcut: compare latest report with previous report for the same dataset.
- Highlight status per metric and cohort.

Endpoint:

| Endpoint | Use |
| --- | --- |
| `GET /reports/{id}/diff/{otherId}` | Signed report deltas |

Wireframe:

```text
+------------------------------------------------------------------+
| Compare Reports                                                  |
| Left: rag.faq #2026-05-06    Right: rag.faq #2026-05-05          |
+----------------------+----------------------+--------------------+
| Metric               | Delta                | Status             |
+----------------------+----------------------+--------------------+
| macro_f1             | +0.012               | improved           |
| exact-match.mean     | -0.006               | stable             |
| judge.pass_rate      | -0.041               | regressed          |
+----------------------+----------------------+--------------------+
| Cohorts: billing regressed, onboarding improved                  |
+------------------------------------------------------------------+
```

### 4.5 Dataset Trend

Purpose: show quality movement for one dataset over recent runs.

Controls:

- Dataset selector.
- Limit selector with values 10, 30, 50, 100.
- Metric overlay selector.
- Cohort selector.
- Usage overlay toggle for token and latency data.

Endpoint:

| Endpoint | Use |
| --- | --- |
| `GET /datasets/{name}/trend?limit=N` | Chronological trend points |

Wireframe:

```text
+------------------------------------------------------------------+
| Dataset Trend: rag.faq      [limit 30 v] [metric macro_f1 v]     |
+------------------------------------------------------------------+
| 0.98 |                                          *                |
| 0.94 |                         *       *------*                  |
| 0.90 |          *------*------*                                  |
| 0.86 | *------*                                                  |
+------------------------------------------------------------------+
| Overlays: [x] macro_f1 [ ] exact-match [ ] tokens [ ] latency    |
| Cohort: [all v]                                                  |
+------------------------------------------------------------------+
```

### 4.6 Adversarial Manifests

Purpose: browse adversarial run history and compliance-oriented safety
coverage.

Screens:

- Manifest list.
- Manifest detail.

Endpoints:

| Endpoint | Use |
| --- | --- |
| `GET /adversarial/manifests` | Manifest discovery |
| `GET /adversarial/manifests/{name}` | Manifest detail |

Wireframe:

```text
+------------------------------------------------------------------+
| Adversarial Manifests                                            |
+----------------------+----------+------------+-------------------+
| Name                 | Runs     | Latest F1  | Compliance        |
+----------------------+----------+------------+-------------------+
| nightly-red-team     | 42       | 0.951      | OWASP,NIST        |
| eu-ai-act-smoke      | 18       | 0.973      | EU AI Act         |
+----------------------+----------+------------+-------------------+
| Detail: category coverage, latest failures, gate outcome         |
+------------------------------------------------------------------+
```

### 4.7 Live Batches

Purpose: monitor in-flight lazy-parallel batches without Horizon.

Behavior:

- Poll every 3 seconds by default.
- Do not cache live responses.
- Remove completed batches from the live list after registry cleanup.
- Show a 503 state if the cache backend is unavailable.

Endpoints:

| Endpoint | Use |
| --- | --- |
| `GET /batches/live` | Active batch ids and expiry metadata |
| `GET /batches/{id}/progress` | Per-batch progress counters and compact status |

Wireframe:

```text
+------------------------------------------------------------------+
| Live Batches                                      [refresh 3s v] |
+----------------------+-------------+------------+---------------+
| Batch                | Status      | Progress   | TTL           |
+----------------------+-------------+------------+---------------+
| batch_01             | running     | 64 / 100   | 820s          |
| batch_02             | running     | 8 / 40     | 600s          |
+----------------------+-------------+------------+---------------+
| batch_01 detail: started_at, last_checkpoint, failures, rate     |
+------------------------------------------------------------------+
```

## 5. Data Contracts

The UI must pin on `schema_version` and inspect the per-payload `schema`
discriminator before rendering endpoint-specific content.

| Screen | Endpoint | Schema |
| --- | --- | --- |
| Reports list | `GET /reports` | `eval-harness.report-api.v1.reports` |
| Report detail | `GET /reports/{id}` | `eval-harness.report-api.v1.report` |
| Cohorts | `GET /reports/{id}/cohorts` | `eval-harness.report-api.v1.cohorts` |
| Histograms | `GET /reports/{id}/histograms` | `eval-harness.report-api.v1.histograms` |
| Diff | `GET /reports/{id}/diff/{otherId}` | `eval-harness.report-api.v1.diff` |
| Adversarial list | `GET /adversarial/manifests` | `eval-harness.report-api.v1.adversarial-manifests` |
| Adversarial detail | `GET /adversarial/manifests/{name}` | `eval-harness.report-api.v1.adversarial-manifest` |
| Live batches | `GET /batches/live` | `eval-harness.report-api.v1.batches-live` |
| Batch progress | `GET /batches/{id}/progress` | `eval-harness.report-api.v1.batch-progress` |
| Dataset trend | `GET /datasets/{name}/trend?limit=N` | `eval-harness.report-api.v1.trend` |

Response handling:

| Status | UI behavior |
| --- | --- |
| 200 | Render normally after schema validation |
| 404 | Show empty/missing state scoped to the widget or screen |
| 422 | Show invalid parameter state and reset filters when possible |
| 503 | Show storage/cache unavailable state with retry affordance |

## 6. Performance And Caching

Default client cache policy:

| Data | Cache |
| --- | --- |
| Reports list | 30 seconds |
| Report detail | 5 minutes |
| Histograms | Forever per report id |
| Cohorts | Forever per report id |
| Diff | 5 minutes per id pair |
| Dataset trend | 5 minutes per dataset plus limit |
| Adversarial manifests | 60 seconds |
| Live batches | No cache |
| Batch progress | No cache |

The UI should deduplicate in-flight requests per endpoint key. Charts should
render progressively with skeleton states and should not block the entire
page when one widget returns 503.

For large report lists, the first UI release can perform client-side search
over the API response. If stored artifact counts become large, the base API
can later add pagination without changing screen concepts.

## 7. Accessibility And I18n

Requirements:

- WCAG AA contrast.
- Keyboard navigation for tables, tabs, selectors, and downloads.
- Visible focus states.
- Chart values available as accessible tables.
- No metric meaning encoded by color alone.
- English and Italian locales from the first public UI release.
- Human-readable labels for known metric names.

Metric labels should be configurable:

```php
'metric_labels' => [
    'exact-match.mean' => 'Exact match',
    'llm-judge.pass_rate' => 'Judge pass rate',
    'macro_f1' => 'Macro F1',
],
```

## 8. UI Package Roadmap

| Version | Scope |
| --- | --- |
| v0.1 | Dashboard, reports list, report detail |
| v0.2 | Compare reports and dataset trend |
| v0.3 | Adversarial manifests and live batches |
| v0.4 | Multi-tenant polish, i18n polish, visual regression suite |

Release gates for the UI package:

- PHPUnit or Pest for backend route/config tests.
- Vitest for client data adapters.
- Playwright smoke tests for the seven primary screens.
- Axe accessibility checks for dashboard, report detail, and trend screens.

## 9. Open Questions

- Should the first implementation use Inertia/Vue as recommended, or
  Livewire 3 for simpler installation in non-SPA host apps?
- Should live batches stay polling-only, or should a later release add SSE
  when the host app supports it?
- Should assets be published as prebuilt files only, or should the package
  also expose source assets for host Vite builds?
- Should Storybook be mandatory, or is Playwright visual regression enough?
- Should trend endpoints eventually support server-side metric selection to
  reduce payload size for very large reports?
- Should tenant scoping be standardized in eval-harness itself or remain a
  host-only integration concern?
