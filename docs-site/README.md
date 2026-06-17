# eval-harness documentation site (docmd)

This folder is the **public documentation site** for
[`padosoft/eval-harness`](https://github.com/padosoft/eval-harness), built with
[docmd](https://docs.docmd.io) — an open-source static documentation generator.

It was migrated from Mintlify to docmd. The conversion is reproducible via
[`scripts/migrate-from-mintlify.mjs`](scripts/migrate-from-mintlify.mjs); see
[`MIGRATION_REPORT.md`](MIGRATION_REPORT.md) for the per-file, per-component log.

It is intentionally separate from the internal engineering docs in
[`/docs`](../docs) (roadmap, rules, progress, lessons, contract specs):
`/docs-site` is the curated, end-user-facing reference, authored at
senior-architect / academic depth.

## Local development

```bash
cd docs-site
npm install
npm run dev      # live-preview server
npm run build    # generate the static site into _site/
npm run check    # fail if any unconverted MDX/Mintlify <Component> tag survives
```

Requires Node.js 18+.

## Layout

| Path | Purpose |
| --- | --- |
| `docmd.config.json` | Site config: metadata, sidebar navigation, theme, plugins. |
| `docs/` | All Markdown pages. Routes mirror the file tree (`docs/guides/ci-gate.md` → `/guides/ci-gate`). |
| `assets/` | Favicon and `custom.css` (preserves the teal brand palette). |
| `scripts/` | `check-no-mdx-tags.mjs` (CI guard) and the one-shot migration script. |
| `_site/` | Build output (git-ignored). |

## Enabled docmd plugins

`search` (**semantic**, client-side — see below), `git` (edit links +
last-updated — needs `fetch-depth: 0` in CI), `seo` (Open Graph / Twitter cards),
`sitemap`, `mermaid` (same ` ```mermaid ` fences), `math` (KaTeX, `$…$` /
`$$…$$`), and `llms` (`llms.txt` + `llms-full.txt`).

### Semantic search

`plugins.search.semantic: true` uses `docmd-search`: embeddings are computed at
**build time** with ONNX Runtime (`@huggingface/transformers` + `onnxruntime-node`,
both devDependencies) and the browser receives only quantised vectors — fully
client-side, no server. The embedding model is pinned in
`.docmd-search/config.json` (`Xenova/all-MiniLM-L6-v2`) so the build is
non-interactive (no model-selection wizard) and reproducible in CI.

## Authoring standard

Pages follow the deep-doc template: **motivation → theory (with formulas where
relevant) → design with a Mermaid diagram → data/contract model → ADR-style
rationale → worked example → gotchas**. New capabilities and README changes
should ship their matching deep page here.

docmd containers used in place of the former Mintlify components:

| Need | Syntax |
| --- | --- |
| Callout | `::: callout info` … `:::` (types: `info`, `tip`, `warning`, `danger`, `success`) |
| Tabs | `::: tabs` + `== tab "Label"` blocks + `:::` |
| Steps | `::: steps` + a numbered list with `**bold titles**` + `:::` |
| Collapsible | `::: collapsible "Title"` … `:::` (add `open` to expand by default) |
| Cards | `::: grids` › `::: grid` › `::: card "Title" icon:lucide-name` › `:::` |

Icons are [Lucide](https://lucide.dev) names in kebab-case (the migration
remapped the original FontAwesome names). Mermaid `flowchart` / `sequenceDiagram`
blocks are unchanged.

## Deployment

`.github/workflows/docs.yml` builds the site (incl. the semantic index) in
GitHub Actions and deploys the static `_site/` to **Cloudflare Pages** via
`cloudflare/wrangler-action`. ONNX runs only in the build job; Cloudflare serves
static assets only.

Required repo secrets: `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_ACCOUNT_ID`.
One-time project creation:

```bash
npx wrangler pages project create eval-harness-docs --production-branch main
```

Then attach the custom domain `doc.eval-harness.padosoft.com` in the Cloudflare
Pages dashboard. The previous Mintlify GitHub App deployment is no longer used.
