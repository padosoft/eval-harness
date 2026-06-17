// One-shot Mintlify (.mdx) -> docmd (.md) converter for the eval-harness docs.
// Reads every *.mdx under docs-site/, writes the docmd equivalent to
// docs-site/docs/<same-path>.md, and emits MIGRATION_REPORT.md. Temporary:
// it is deleted once the migration is verified.
import { readFileSync, writeFileSync, readdirSync, statSync, mkdirSync, rmSync } from 'node:fs';
import { join, dirname, relative, sep } from 'node:path';

const ROOT = process.cwd();              // docs-site/
const OUT = join(ROOT, 'docs');          // docs-site/docs/

// FontAwesome (Mintlify) -> Lucide (docmd), kebab-case. Anything missing is
// reported as a TODO and passed through verbatim so the build still runs.
const ICON_MAP = {
  'function': 'square-function', 'wave-pulse': 'activity', 'layer-group': 'layers',
  'file-code': 'file-code', 'traffic-light': 'traffic-cone', 'shield-halved': 'shield',
  'scale-balanced': 'scale', 'diagram-project': 'workflow', 'sitemap': 'network',
  'sigma': 'sigma', 'ruler-combined': 'ruler', 'play': 'play', 'gear': 'settings',
  'vector-square': 'vector-square', 'table-list': 'table', 'server': 'server',
  'seedling': 'sprout', 'plug': 'plug', 'lock-open': 'lock-open', 'wrench': 'wrench',
  'user-shield': 'user-check', 'user-secret': 'venetian-mask', 'terminal': 'terminal',
  'syringe': 'syringe', 'spell-check': 'spell-check', 'scroll': 'scroll',
  'ruler-horizontal': 'ruler', 'robot': 'bot', 'ranking-star': 'trophy',
  'network-wired': 'network', 'lock': 'lock', 'id-card': 'id-card',
  'hourglass-half': 'hourglass', 'handshake': 'handshake', 'ghost': 'ghost',
  'download': 'download', 'code-compare': 'git-compare', 'cloud': 'cloud',
  'chart-line-down': 'trending-down', 'chart-line': 'trending-up', 'bolt': 'zap',
  'book-open': 'book-open', 'rocket': 'rocket', 'lightbulb': 'lightbulb',
  'book-bookmark': 'book-marked', 'bug': 'bug',
};

const report = [];
const unmappedIcons = new Set();

function mapIcon(fa) {
  if (ICON_MAP[fa]) return ICON_MAP[fa];
  unmappedIcons.add(fa);
  return fa; // pass through, flagged in report
}

// Remove the smallest common leading-whitespace from a block of text.
function dedent(text) {
  const lines = text.replace(/\t/g, '    ').split('\n');
  let min = Infinity;
  for (const l of lines) {
    if (l.trim() === '') continue;
    const m = l.match(/^ */)[0].length;
    if (m < min) min = m;
  }
  if (!isFinite(min)) min = 0;
  return lines.map(l => l.slice(min)).join('\n').replace(/^\n+/, '').replace(/\s+$/, '');
}

function indent(text, n) {
  const pad = ' '.repeat(n);
  return text.split('\n').map(l => (l.trim() === '' ? '' : pad + l)).join('\n');
}

// Convert Mintlify callouts to docmd. Operates on already-dedented text so it
// can be reused both at top level and inside container bodies (e.g. a <Tip>
// nested in a <Step>). `log` collects per-file conversion notes.
const CALLOUT_TYPE = { Note: 'info', Tip: 'tip', Warning: 'warning', Info: 'info', Check: 'success', Danger: 'danger' };
function convertCallouts(text, log) {
  let out = text;
  for (const [tag, type] of Object.entries(CALLOUT_TYPE)) {
    const re = new RegExp(`<${tag}>([\\s\\S]*?)<\\/${tag}>`, 'g');
    out = out.replace(re, (_, inner) => {
      if (log) log.push(`OK: <${tag}> -> ::: callout ${type}`);
      return `::: callout ${type}\n${dedent(inner)}\n:::`;
    });
  }
  return out;
}

function convert(src, relPath) {
  const log = [];
  let body = src;

  // --- frontmatter: keep title/description, drop Mintlify-only `icon` ---
  const fm = body.match(/^---\n([\s\S]*?)\n---\n?/);
  let front = '';
  if (fm) {
    const kept = fm[1].split('\n').filter(l => !/^icon:\s*/.test(l)).join('\n');
    front = `---\n${kept}\n---\n`;
    body = body.slice(fm[0].length);
  }

  // --- inline cleanups ---
  body = body.replace(/<code>([\s\S]*?)<\/code>/g, (_, c) => '`' + c.trim() + '`');
  body = body.replace(/&nbsp;/g, ' ');

  // Block containers are converted first. Each handler dedents the raw inner
  // body, recursively converts callouts inside it, and (for steps) re-indents —
  // so a <Tip> nested in a <Step> lands at the right depth. Top-level callouts
  // are handled afterwards.

  // --- Tabs ---
  body = body.replace(/<Tabs>([\s\S]*?)<\/Tabs>/g, (_, inner) => {
    const tabs = [...inner.matchAll(/<Tab\s+title="([^"]*)"\s*>([\s\S]*?)<\/Tab>/g)];
    log.push(`OK: <Tabs> (${tabs.length}) -> ::: tabs`);
    const parts = tabs.map(([, title, content]) => `== tab "${title}"\n${convertCallouts(dedent(content), log)}`);
    return `::: tabs\n\n${parts.join('\n\n')}\n\n:::`;
  });

  // --- Steps -> ::: steps with a 3-space-indented ordered list ---
  body = body.replace(/<Steps>([\s\S]*?)<\/Steps>/g, (_, inner) => {
    const steps = [...inner.matchAll(/<Step\s+title="([^"]*)"\s*>([\s\S]*?)<\/Step>/g)];
    log.push(`OK: <Steps> (${steps.length}) -> ::: steps`);
    const items = steps.map(([, title, content], i) => {
      const bodyTxt = convertCallouts(dedent(content), log);
      const indented = bodyTxt ? '\n' + indent(bodyTxt, 3) : '';
      return `${i + 1}. **${title}**${indented}`;
    });
    return `::: steps\n\n${items.join('\n\n')}\n\n:::`;
  });

  // --- Accordion / AccordionGroup -> sequential collapsibles ---
  body = body.replace(/<AccordionGroup>([\s\S]*?)<\/AccordionGroup>/g, (_, inner) => inner);
  body = body.replace(/<Accordion\s+title="([^"]*)"\s*>([\s\S]*?)<\/Accordion>/g, (_, title, content) => {
    log.push(`OK: <Accordion "${title}"> -> ::: collapsible`);
    return `::: collapsible "${title}"\n${convertCallouts(dedent(content), log)}\n:::`;
  });

  // --- CardGroup / Card -> grids + grid + card (+ button for href) ---
  const buildCard = (attrs, content) => {
    const title = (attrs.match(/title="([^"]*)"/) || [, ''])[1];
    const iconFa = (attrs.match(/icon="([^"]*)"/) || [, ''])[1];
    const href = (attrs.match(/href="([^"]*)"/) || [, ''])[1];
    const iconPart = iconFa ? ` icon:${mapIcon(iconFa)}` : '';
    const desc = convertCallouts(dedent(content), log);
    // A plain markdown link is used instead of a `::: button` block: docmd's
    // button directive is not a paired container here, so a trailing `:::`
    // leaks as literal text. The link renders reliably and stays AI-readable.
    const btn = href ? `\n\n[Open →](${href})` : '';
    return { title, card: `::: card "${title}"${iconPart}\n${desc}${btn}\n:::` };
  };
  body = body.replace(/<CardGroup[^>]*>([\s\S]*?)<\/CardGroup>/g, (_, inner) => {
    const cards = [...inner.matchAll(/<Card\s+([^>]*?)>([\s\S]*?)<\/Card>/g)];
    log.push(`OK: <CardGroup> (${cards.length}) -> ::: grids`);
    const blocks = cards.map(([, attrs, content]) => `::: grid\n${buildCard(attrs, content).card}\n:::`);
    return `::: grids\n${blocks.join('\n')}\n:::`;
  });
  // any standalone (non-grouped) Cards
  body = body.replace(/<Card\s+([^>]*?)>([\s\S]*?)<\/Card>/g, (_, attrs, content) => {
    const { title, card } = buildCard(attrs, content);
    log.push(`OK: <Card "${title}"> -> ::: card`);
    return card;
  });

  // --- remaining top-level callouts ---
  body = convertCallouts(body, log);

  // --- leftover detection ---
  const leftovers = [...body.matchAll(/<\/?[A-Z][A-Za-z]+/g)].map(m => m[0]);
  for (const l of new Set(leftovers)) log.push(`TODO: unconverted tag ${l}`);

  report.push({ file: relPath, log });
  return front + '\n' + body.replace(/\n{3,}/g, '\n\n').trimStart() + '\n';
}

function walk(dir) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    const st = statSync(p);
    if (st.isDirectory()) {
      if (name === 'docs' || name === 'node_modules' || name === '_site' || name.startsWith('.')) continue;
      walk(p);
      continue;
    }
    if (!name.endsWith('.mdx')) continue;
    const rel = relative(ROOT, p);
    // introduction is the site landing page -> docs/index.md (route "/").
    const outRel = rel === 'introduction.mdx' ? 'index.md' : rel.replace(/\.mdx$/, '.md');
    const outPath = join(OUT, outRel);
    mkdirSync(dirname(outPath), { recursive: true });
    const converted = convert(readFileSync(p, 'utf8'), rel.split(sep).join('/'));
    writeFileSync(outPath, converted, 'utf8');
    rmSync(p);
  }
}

mkdirSync(OUT, { recursive: true });
walk(ROOT);

// --- write report ---
let md = '# Mintlify → docmd migration report\n\n';
md += `Converted ${report.length} files.\n\n`;
if (unmappedIcons.size) {
  md += `## ⚠️ Icons passed through unmapped (verify against Lucide)\n\n`;
  md += [...unmappedIcons].map(i => `- \`${i}\``).join('\n') + '\n\n';
} else {
  md += `## Icons\n\nAll FontAwesome icons mapped to Lucide. ✅\n\n`;
}
const todos = report.filter(r => r.log.some(l => l.startsWith('TODO')));
md += `## Unconverted tags\n\n${todos.length ? '' : 'None. ✅\n\n'}`;
for (const r of todos) md += `### ${r.file}\n` + r.log.filter(l => l.startsWith('TODO')).map(l => `- ${l}`).join('\n') + '\n\n';
md += `## Per-file conversion log\n\n`;
for (const r of report) {
  md += `### \`${r.file}\`\n`;
  md += r.log.length ? r.log.map(l => `- ${l}`).join('\n') + '\n\n' : '- (plain markdown, no components)\n\n';
}
writeFileSync(join(ROOT, 'MIGRATION_REPORT.md'), md, 'utf8');
console.log(`Converted ${report.length} files. Unmapped icons: ${unmappedIcons.size}. Files with TODOs: ${todos.length}.`);
