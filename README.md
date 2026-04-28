# eval-harness

Agentic eval framework for Laravel. **Regression-block your AI before merge.**

## Features

- Golden dataset format (JSON, version-controlled)
- LLM-as-judge scoring (groundedness, helpfulness, refusal quality, safety)
- Regression detection (block PR if quality drops > 5%)
- Adversarial harness (prompt injection, jailbreak, tool abuse)
- CI integration via Artisan commands
- Multi-model comparison

## Installation

```bash
composer require padosoft/eval-harness --dev
```

## Quick start

```bash
php artisan eval:run --dataset=path/to/golden.json --agent=KnowledgeAgent
```

```bash
php artisan eval:adversarial --agent=KnowledgeAgent
```

## Documentation

See [docs/](./docs/) for full API reference.

## License

Apache-2.0 — see [LICENSE](./LICENSE).

## Status

🚧 Pre-release. v0.1.0 expected July 2026.
