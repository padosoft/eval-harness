---
name: docmd-docs
description: Author, build, and maintain the docmd documentation site in docs-site/. Use when editing docs-site/, adding or updating documentation pages, changing the sidebar/navigation, configuring docmd plugins, re-running the Mintlify→docmd migration, or keeping the docs in sync with new/changed features (see rule-docmd-docs-sync). Covers the exact docmd container syntax, the FontAwesome→Lucide icon mapping, plugin config, the build/verify commands, and the gotchas hit during the original migration.
---

# docmd documentation site

The public docs live in `docs-site/`, built with **docmd** (`@docmd/core`, npm).
It was migrated from Mintlify (MDX). This skill is the operating manual.

## Layout

| Path | Purpose |
| --- | --- |
| `docs-site/docmd.config.json` | Site metadata, `url`, sidebar `navigation[]`, theme, `plugins`. |
| `docs-site/docs/**.md` | Pages. Route = path under `docs/` (`docs/guides/ci-gate.md` → `/guides/ci-gate`). `docs/index.md` is the home page (route `/`) and is the former `introduction`. |
| `docs-site/assets/` | `favicon.svg`, `custom.css` (teal brand: `#0d9488` / `#14b8a6` / `#0f766e`). |
| `docs-site/scripts/migrate-from-mintlify.mjs` | One-shot, reproducible Mintlify→docmd converter. |
| `docs-site/scripts/check-no-raw-html.mjs` | CI guard: fails if raw HTML/MDX-like tags or `::: button` survive. |
| `docs-site/_site/` | Build output (git-ignored). |

## Commands

```bash
cd docs-site
npm install
npm run dev      # live preview at http://localhost:3000
npm run build    # static build into _site/
npm run check    # guard: no raw HTML/MDX-like tags or ::: button blocks
```

Node 18+. Current pinned engine: `@docmd/core ^0.8.6`. Confirm the live version
with `npm view @docmd/core version` before pinning — do NOT invent a version
(the first migration failed pinning a non-existent `^0.1.0`).

## Authoring containers (docmd syntax)

Plain Markdown plus `:::` containers. There is **no MDX / JSX / raw HTML** —
never write `<Card>`, `<Note>`, `<br>`, etc. (the guard rejects them).

| Need | Syntax |
| --- | --- |
| Callout | `::: callout info` … `:::` — types: `info`, `tip`, `warning`, `danger`, `success` |
| Tabs | `::: tabs` then `== tab "Label"` blocks, close `:::` |
| Steps | `::: steps` then a numbered list `1. **Title**` with body indented **3 spaces**, close `:::` |
| Collapsible | `::: collapsible "Title"` … `:::` (prefix `open` to expand by default). docmd has **no grouped/exclusive accordion** — emit independent collapsibles. |
| Cards | `::: grids` › `::: grid` › `::: card "Title" icon:lucide-name` › body › optional `[Open →](/path)` › `:::` (close card) › `:::` (close grid) › `:::` (close grids) |
| Mermaid | a fenced ` ```mermaid ` block — unchanged from Mintlify |
| Math | KaTeX: inline `$…$`, block `$$…$$` (math plugin) |

Icons are **Lucide** kebab-case names (https://lucide.dev), set via `icon:name`
in cards and the config `navigation[]`. Mintlify used FontAwesome — they are NOT
interchangeable.

## Plugins (configured in docmd.config.json → `plugins`)

`search` (semantic — see below), `git` (edit links + last-updated; needs `repo`
+ CI `fetch-depth: 0`), `seo` (OG/Twitter), `sitemap` (needs root `url`),
`mermaid`, `math` (KaTeX), `llms` (`llms.txt` + `llms-full.txt`, `fullContext`).
The site `url` is `https://doc.eval-harness.padosoft.com`.

## Semantic search (docmd-search)

Enabled via `plugins.search.semantic: true`. Build-time deps (devDependencies):
`docmd-search`, `@huggingface/transformers`, `onnxruntime-node`. Embeddings are
computed **at build time** with ONNX Runtime; the browser only gets quantised
Int8 vectors and does keyword + cosine matching — fully client-side, no server,
no model weights shipped. Output lands in `_site/.docmd-search/` (`manifest.json`
+ `batches/*.bin`) plus `assets/js/docmd-search.js`.

**Critical for CI:** the first build otherwise runs an *interactive* model-picker
wizard that hangs in headless runs. Avoid it by pinning the model in a committed
project config `docs-site/.docmd-search/config.json`:

```json
{ "model": "Xenova/all-MiniLM-L6-v2", "chunkSize": 512, "chunkOverlap": 64, "incremental": true, "topK": 10 }
```

Model resolution order: built-in default → `~/.docmd-search/config.json` (global,
where the wizard writes — do NOT rely on it) → project `.docmd-search/config.json`
→ CLI override. Models: `Xenova/all-MiniLM-L6-v2` (EN, ~23 MB, recommended — our
docs are English), or multilingual `Xenova/multilingual-e5-small` /
`paraphrase-multilingual-*`. `.gitignore` keeps `config.json`, ignores the rest
of `.docmd-search/`.

## Footer & branding

`docmd.config.json` → `footer: { style, branding, content, columns }`. `content`
is Markdown (used for the "© Lorenzo Padovani — Padosoft" credit); `columns[]`
hold link groups (`{ title, links: [{ title, path, external }] }`); `branding`
keeps the "Powered by docmd" mark.

## Deploy: Cloudflare Pages (Option A — active, confirmed working)

The site is deployed via **Cloudflare Pages' Git integration**: CF builds on every
push to `main`, no API key. **Confirmed working** — the semantic-search build
(`onnxruntime-node` + `@huggingface/transformers`) runs fine on CF's Linux build
image. CF Pages build config: root `docs-site`, build command `npm run build`,
output `_site`, Node 20 (auto via `docs-site/.node-version`). The lockfile is v3
and cross-platform, so CF's Linux `npm ci` resolves the right native binaries
(`sharp-linux-x64`, onnxruntime for linux). Custom domain
`doc.eval-harness.padosoft.com` is attached in the Pages dashboard.

**Fallback (Option B — not needed, kept disabled):** `.github/workflows/docs.yml`
builds in GitHub Actions and uploads `_site/` via `cloudflare/wrangler-action`
(needs `CLOUDFLARE_API_TOKEN` + `CLOUDFLARE_ACCOUNT_ID`). It is `workflow_dispatch`-
only (never auto-runs). Use it only if CF's own build ever breaks on ONNX.

## Navigation

Sidebar is the `navigation[]` array in `docmd.config.json` — NOT frontmatter and
NOT auto-generated. Each item: `{ "title", "path", "icon"?, "children"?, "external"? }`.
When you add a page, add its nav entry too, or it won't appear in the sidebar.

## Gotchas (learned the hard way — verify with a real build, don't assume)

1. **Root page required.** docmd needs a page at route `/` or the build warns
   "Root index.html not found" and `/` 404s. Keep `docs/index.md`.
2. **`::: button` is NOT a paired block** the way cards docs imply — a trailing
   `:::` leaks as literal text. Use a plain Markdown link inside the card
   (`[Open →](/path)`) instead.
3. **Steps body indentation:** dedent the source body to flush, then re-indent
   exactly 3 spaces so nested code fences / callouts stay inside the list item.
4. **Math false positives:** the math plugin renders `$…$` only outside code.
   PHP `$variables` in fenced code are safe; bare `$` pairs in prose are not —
   check rendered HTML for stray `class="katex"` on code-heavy pages.
5. **Re-running the migration** deletes the source `.mdx`. Restore originals with
   `git checkout -- 'docs-site/*.mdx'` before re-running the converter.

## After any docs change

Run `npm run check` then `npm run build` and confirm:
`_site/index.html` exists, 31+ pages generated, and (grep) **zero** literal `:::`
in visible HTML text (only inside `class="docmd-container …"` markup).
