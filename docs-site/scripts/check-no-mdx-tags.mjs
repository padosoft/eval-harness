// CI guard: fail if any unconverted Mintlify/MDX component tag survives in the
// docmd markdown. Catches accidental reintroduction of <Card>, <Note>, etc.
import { readdirSync, statSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const DOCS = join(process.cwd(), 'docs');
// Capitalised JSX-style tags only — leaves prose like "<https://...>" alone.
const TAG = /<\/?[A-Z][A-Za-z0-9]*(\s|>|\/)/g;
const offenders = [];

function walk(dir) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) { walk(p); continue; }
    if (!name.endsWith('.md')) continue;
    const text = readFileSync(p, 'utf8');
    text.split('\n').forEach((line, i) => {
      // ignore fenced/inline code is overkill here; component tags never appear
      // legitimately in these docs, so any match is a real offender.
      const m = line.match(TAG);
      if (m) offenders.push(`${p}:${i + 1}  ${m.join(' ')}`);
    });
  }
}

walk(DOCS);
if (offenders.length) {
  console.error('Unconverted MDX/Mintlify component tags found:\n' + offenders.join('\n'));
  process.exit(1);
}
console.log('OK: no unconverted MDX component tags.');
