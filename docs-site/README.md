# eval-harness documentation site (Mintlify)

This folder is the **public documentation site** for
[`padosoft/eval-harness`](https://github.com/padosoft/eval-harness), published
with [Mintlify](https://mintlify.com) at
**[doc.eval-harness.padosoft.com](https://doc.eval-harness.padosoft.com/)**.

It is intentionally separate from the internal engineering docs in
[`/docs`](../docs) (roadmap, rules, progress, lessons, contract specs):
`/docs-site` is the curated, end-user-facing reference, authored at
senior-architect / academic depth.

## Local preview

```bash
npm i -g mint        # one-time: the Mintlify CLI
cd docs-site
mint dev             # http://localhost:3000
```

`mint dev` renders the site from `docs.json` + the `*.mdx` pages and reports
broken links.

## Layout

- `docs.json` — site config + the groups-based navigation. **Every page must be
  registered here**, and Mintlify errors on a nav entry whose `.mdx` file does
  not exist.
- `*.mdx`, `guides/*.mdx`, `metrics/*.mdx`, `best-practices/*.mdx`,
  `operations/*.mdx`, `architecture/*.mdx`, `reference/*.mdx` — one file per
  page.
- `favicon.svg` — site favicon.

## Authoring standard

Pages follow the deep-doc template: **motivation → theory (with formulas where
relevant) → design with a Mermaid diagram → data/contract model → ADR-style
rationale → worked example → gotchas**. New capabilities and README changes
should ship their matching deep page here. Components used: `<Note>`,
`<Warning>`, `<Tip>`, `<Steps>`, `<CardGroup>`/`<Card>`,
`<AccordionGroup>`/`<Accordion>`, and Mermaid `flowchart` / `sequenceDiagram`
blocks.

## Deployment

Connect the Mintlify GitHub App to this repository with the content directory
set to `docs-site/`. Every push to `main` that touches `docs-site/`
auto-deploys to the live site at
[doc.eval-harness.padosoft.com](https://doc.eval-harness.padosoft.com/).
