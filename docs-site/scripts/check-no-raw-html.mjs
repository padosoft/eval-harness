// CI guard: fail if raw HTML/MDX-like markup or known-bad docmd blocks leak
// into Markdown docs. Mermaid's HTML-like labels are allowed inside fences.
import { readdirSync, statSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const DOCS = join(process.cwd(), 'docs');
const RAW_TAG = /<\/?[A-Za-z][A-Za-z0-9-]*(?:\s|>|\/)/g;
const BAD_CONTAINER = /:::\s*button\b/i;
const offenders = [];

function scanMarkdown(path) {
  const text = readFileSync(path, 'utf8');
  let inFence = false;

  text.split('\n').forEach((line, index) => {
    if (/^\s*```/.test(line)) {
      inFence = !inFence;
      return;
    }

    if (inFence) {
      return;
    }

    const prose = line.replace(/`[^`]*`/g, '');

    if (BAD_CONTAINER.test(prose)) {
      offenders.push(`${path}:${index + 1}  ::: button is not supported`);
    }

    const matches = prose.match(RAW_TAG);
    if (matches) {
      offenders.push(`${path}:${index + 1}  raw HTML/MDX tag: ${matches.join(' ')}`);
    }
  });
}

function walk(dir) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    if (statSync(path).isDirectory()) {
      walk(path);
      continue;
    }

    if (name.endsWith('.md')) {
      scanMarkdown(path);
    }
  }
}

walk(DOCS);

if (offenders.length) {
  console.error('Raw HTML/MDX-like syntax found:\n' + offenders.join('\n'));
  process.exit(1);
}

console.log('OK: no raw HTML/MDX-like syntax in Markdown docs.');
