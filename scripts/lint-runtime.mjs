#!/usr/bin/env node
/**
 * lint-runtime.mjs — Slug-Boundary-Wächter für element-runtime.min.js.
 *
 * Erzwingt das Architektur-Prinzip aus
 * `feedback_runtime_contract_boundary.md`: der gefrorene Plugin-Runtime
 * darf NIE slug-spezifischen Code enthalten. Mapper, Wrapper und
 * Vendor-Components leben ausschließlich in den Storage-deployten
 * Element-Chunks (`el-*.min.js`).
 *
 * Wenn dieser Wächter rot wird, hat ein PR versehentlich slug-Logik in
 * den Runtime gezogen — typischerweise durch einen Import, der einen
 * MAPPERS-Lookup oder Slug-Switch in den Runtime-Bundle pulled. Fix:
 * den slug-spezifischen Code in `effects/<slug>/index.tsx` umziehen
 * und nur slug-agnostische Helper im `_runtime/` lassen.
 *
 * Usage:
 *   npm run lint:runtime
 */

import { readFileSync, existsSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const SCRIPT_DIR = dirname(fileURLToPath(import.meta.url));
const PLUGIN_ROOT = resolve(SCRIPT_DIR, '..');
const RUNTIME_PATH = resolve(PLUGIN_ROOT, 'assets/js/elements/element-runtime.min.js');

// Slugs that have their own chunk and must NEVER be referenced by name
// from the runtime bundle. Keep in sync with effects/*/index.tsx.
const FORBIDDEN_SLUGS = [
  'circular-gallery',
  'masonry',
  'dome-gallery',
  'flowing-menu',
  'infinite-menu',
  'bounce-cards',
  'chroma-grid',
  'tilted-card',
];

if (!existsSync(RUNTIME_PATH)) {
  console.error(`[lint-runtime] runtime bundle not found at ${RUNTIME_PATH}`);
  console.error(`[lint-runtime] run \`npm run build:elements\` first.`);
  process.exit(1);
}

const bundle = readFileSync(RUNTIME_PATH, 'utf8');
const findings = [];

for (const slug of FORBIDDEN_SLUGS) {
  // Match the slug as a quoted string literal — that's how MAPPERS-style
  // dictionaries encode it. Plain substring match would false-positive on
  // CSS/class-name fragments.
  const re = new RegExp(`["']${slug.replace(/[-/\\^$*+?.()|[\]{}]/g, '\\$&')}["']`, 'g');
  const hits = bundle.match(re);
  if (hits && hits.length > 0) {
    findings.push({ slug, count: hits.length });
  }
}

if (findings.length === 0) {
  const sizeKB = (bundle.length / 1024).toFixed(1);
  console.log(`[lint-runtime] OK — runtime bundle is slug-agnostic (${sizeKB} KB).`);
  process.exit(0);
}

console.error('[lint-runtime] FAIL — runtime bundle leaks slug-specific code:');
for (const { slug, count } of findings) {
  console.error(`  - "${slug}" appears ${count}× in element-runtime.min.js`);
}
console.error('');
console.error('Fix: move slug-specific code (mappers, wrappers, vendor refs)');
console.error('out of `_runtime/` into the chunk under `effects/<slug>/index.tsx`.');
console.error('See `feedback_runtime_contract_boundary.md` for the contract.');
process.exit(1);
