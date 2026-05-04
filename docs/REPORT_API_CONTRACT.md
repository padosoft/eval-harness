# Report API Contracts

This package exposes read-only report API routes for external UI packages.
All routes are opt-in via `eval-harness.api.enabled`.

## Common conventions

- `id` is the URL-safe encoded report path (for example `cmFnL3JlcG9ydC5qc29u` for `rag/report.json`).
- `schema_version` is always the API schema version constant for machine checks.
- JSON endpoints return:

```json
{
  "schema_version": "eval-harness.api.v1",
  "data": { ... }
}
```

## Endpoints

### List artifacts

- `GET /<prefix>/reports`
- Returns manifest entries:

```json
{
  "data": [
    {
      "id": "cmFnL3JlcG9ydC5qc29u",
      "name": "report.json",
      "path": "eval-harness/reports/rag/report.json",
      "format": "json",
      "size": 12345,
      "last_modified": "2026-05-04T21:02:10+00:00",
      "schema": "eval-harness.report.v1"
    }
  ],
  "pagination": null
}
```

### Show artifact

- `GET /<prefix>/reports/{id}`
- JSON or Markdown raw payload:

```json
{
  "schema_version": "eval-harness.api.v1",
  "data": {
    "artifact": {
      "id": "cmFnL3JlcG9ydC5qc29u",
      "name": "report.json",
      "path": "eval-harness/reports/rag/report.json",
      "format": "json",
      "size": 12345,
      "last_modified": "2026-05-04T21:02:10+00:00",
      "schema": "eval-harness.report.v1"
    },
    "content": {
      "...": "..."
    }
  }
}
```

### Cohorts

- `GET /<prefix>/reports/{id}/cohorts`
- Returns JSON-only report cohorts:

```json
{
  "schema_version": "eval-harness.api.v1",
  "data": {
    "artifact": {
      "...": "..."
    },
    "cohorts": [
      {
        "name": "default",
        "label": "default",
        "is_untagged": false,
        "sample_count": 10,
        "metrics": {
          "exact-match": {
            "mean": 0.9
          }
        }
      }
    ]
  }
}
```

### Histograms

- `GET /<prefix>/reports/{id}/histograms`
- Returns JSON-only `metric_distributions` keyed by metric:

```json
{
  "schema_version": "eval-harness.api.v1",
  "data": {
    "artifact": { "...": "..." },
    "histograms": {
      "exact-match": [
        { "min": 0.0, "max": 0.25, "count": 1 },
        { "min": 0.25, "max": 0.5, "count": 2 },
        { "min": 0.5, "max": 0.75, "count": 5 }
      ]
    }
  }
}
```

### Rows CSV

- `GET /<prefix>/reports/{id}/rows.csv`
- Returns CSV with rows for each `(sample, metric)` pair:

```csv
sample_id,tags,metric,score,error,details
s1,"[\"easy\"]",exact-match,1,
s2,"[\"hard\"]",exact-match,,timeout,""
```

### Download

- `GET /<prefix>/reports/{id}/download`
- Returns the original artifact bytes as an attachment (`.json` or `.md`).

## Error behavior

- Missing/invalid `id` -> `404`.
- Malformed JSON routes (for JSON-only endpoints) -> `422`.
- Metadata/content read failures -> `503`.
