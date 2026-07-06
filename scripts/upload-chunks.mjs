#!/usr/bin/env node
/**
 * upload-chunks.mjs — Idempotent, SHA-256-gated Supabase Storage upload for
 * asset push pipelines (Element Library, Backgrounds, Text Effects).
 *
 * Runs as the last step of each `npm run build:<kind>` so the Storage bucket
 * stays in lock-step with the local build output. Skips re-upload when the
 * remote object already carries a matching `x-hubbee-sha256` metadata header,
 * so repeated runs cost a few HEADs and nothing else.
 *
 * Usage:
 *   node scripts/upload-chunks.mjs --kind=elements
 *   node scripts/upload-chunks.mjs --kind=backgrounds
 *   node scripts/upload-chunks.mjs --kind=text-effects      (alias: textfx)
 *
 * Env (loaded from shell; falls back to .env.local then .env in repo root):
 *   SUPABASE_URL              — required
 *   SUPABASE_SERVICE_ROLE_KEY — required (service_role for upsert + metadata)
 *
 * Outputs:
 *   WP_hubbee/dist/<kind>-chunks-manifest.json         (local, committed)
 *   Storage: <bucket>/_manifest.json                   (read by EF at cold start)
 *
 * Exit codes:
 *   0  success (all chunks up-to-date or freshly uploaded)
 *   1  misuse (bad CLI args, missing env)
 *   2  upload error (network, auth, or bucket error)
 */

import { createHash } from 'node:crypto'
import { existsSync, readFileSync, readdirSync, mkdirSync, statSync, writeFileSync } from 'node:fs'
import { resolve, dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'

// ── Kind config ────────────────────────────────────────────────────────────
const KINDS = {
  elements: { dir: 'assets/js/elements', prefix: 'el-', bucket: 'element-chunks' },
  backgrounds: { dir: 'assets/js/backgrounds', prefix: 'bg-', bucket: 'background-chunks' },
  'text-effects': { dir: 'assets/js/textfx', prefix: 'tx-', bucket: 'text-effect-chunks' },
  textfx: { dir: 'assets/js/textfx', prefix: 'tx-', bucket: 'text-effect-chunks' },
}

// ── CLI parsing ────────────────────────────────────────────────────────────
function parseArgs() {
  const args = process.argv.slice(2)
  let kind = null
  let dryRun = false
  for (const arg of args) {
    if (arg.startsWith('--kind=')) kind = arg.slice('--kind='.length)
    else if (arg === '--dry-run') dryRun = true
    else if (arg === '--help' || arg === '-h') {
      console.log('Usage: node scripts/upload-chunks.mjs --kind=elements|backgrounds|text-effects [--dry-run]')
      process.exit(0)
    }
  }
  if (!kind || !KINDS[kind]) {
    console.error(`ERROR: --kind=<elements|backgrounds|text-effects> is required`)
    process.exit(1)
  }
  return { kind, dryRun, ...KINDS[kind] }
}

// ── Env loader (shell → .env.local → .env in repo root) ────────────────────
function loadEnv() {
  const scriptDir = dirname(fileURLToPath(import.meta.url))
  // scripts/ lives in WP_hubbee/, so repo root is two levels up
  const repoRoot = resolve(scriptDir, '..', '..')

  const fileVars = {}
  for (const name of ['.env.local', '.env']) {
    const p = join(repoRoot, name)
    if (!existsSync(p)) continue
    for (const line of readFileSync(p, 'utf8').split('\n')) {
      const match = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/)
      if (!match) continue
      let [, key, value] = match
      value = value.trim()
      if ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"))) {
        value = value.slice(1, -1)
      }
      if (!(key in fileVars)) fileVars[key] = value
    }
  }

  const SUPABASE_URL = process.env.SUPABASE_URL || fileVars.SUPABASE_URL || fileVars.VITE_SUPABASE_URL
  const SUPABASE_SERVICE_ROLE_KEY = process.env.SUPABASE_SERVICE_ROLE_KEY || fileVars.SUPABASE_SERVICE_ROLE_KEY

  if (!SUPABASE_URL || !SUPABASE_SERVICE_ROLE_KEY) {
    console.error('ERROR: SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY must be set (shell env or .env/.env.local).')
    process.exit(1)
  }
  return {
    SUPABASE_URL: SUPABASE_URL.replace(/\/+$/, ''),
    SUPABASE_SERVICE_ROLE_KEY,
  }
}

// ── Chunk discovery ────────────────────────────────────────────────────────
function findChunks({ dir, prefix }) {
  if (!existsSync(dir)) {
    console.error(`ERROR: chunk dir does not exist: ${dir}`)
    process.exit(1)
  }
  const entries = readdirSync(dir)
    .filter((name) => name.startsWith(prefix) && name.endsWith('.min.js'))
    .map((name) => {
      const slug = name.slice(prefix.length, -'.min.js'.length)
      const path = join(dir, name)
      const size = statSync(path).size
      return { name, slug, path, size }
    })
    .sort((a, b) => a.slug.localeCompare(b.slug))
  return entries
}

function sha256Of(path) {
  const hash = createHash('sha256')
  hash.update(readFileSync(path))
  return 'sha256:' + hash.digest('hex')
}

// ── Supabase Storage helpers ───────────────────────────────────────────────
function storageUrl({ SUPABASE_URL, bucket, path }) {
  return `${SUPABASE_URL}/storage/v1/object/${bucket}/${path}`
}

function authHeaders(key) {
  // Supabase REST expects both headers: apikey identifies the project,
  // Authorization carries the role. Without apikey Storage returns 403
  // even with a valid service_role bearer.
  return {
    apikey: key,
    Authorization: `Bearer ${key}`,
  }
}

/**
 * Load the existing _manifest.json from the bucket. Returns {} if it's
 * not there yet (first run) so we upload everything.
 */
async function loadRemoteManifest({ SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY, bucket }) {
  try {
    const res = await fetch(storageUrl({ SUPABASE_URL, bucket, path: '_manifest.json' }), {
      headers: authHeaders(SUPABASE_SERVICE_ROLE_KEY),
      signal: AbortSignal.timeout(10_000),
    })
    if (!res.ok) return {}
    const data = await res.json()
    if (!data || typeof data !== 'object') return {}
    return data
  } catch {
    return {}
  }
}

async function putChunk({ SUPABASE_URL, SUPABASE_SERVICE_ROLE_KEY, bucket, path, body, contentType }) {
  const res = await fetch(storageUrl({ SUPABASE_URL, bucket, path }), {
    method: 'POST',
    headers: {
      ...authHeaders(SUPABASE_SERVICE_ROLE_KEY),
      'Content-Type': contentType,
      'Cache-Control': 'public, max-age=31536000, immutable',
      'x-upsert': 'true',
    },
    body,
    signal: AbortSignal.timeout(60_000),
  })
  if (!res.ok) {
    const text = await res.text().catch(() => '')
    throw new Error(`Upload failed (${res.status}): ${text.slice(0, 500)}`)
  }
}

// ── Main ───────────────────────────────────────────────────────────────────
async function main() {
  const { kind, dryRun, dir, prefix, bucket } = parseArgs()
  const env = loadEnv()

  const chunks = findChunks({ dir, prefix })
  if (chunks.length === 0) {
    console.error(`ERROR: no chunks found under ${dir}/${prefix}*.min.js`)
    process.exit(1)
  }

  console.log(`[upload-chunks] kind=${kind} bucket=${bucket} dir=${dir} found=${chunks.length}${dryRun ? ' (dry-run)' : ''}`)

  // Remote manifest is the source of truth for skip-if-same. Metadata on
  // storage objects is not reliably exposed via REST HEAD, so we compare
  // local SHA-256 against the hash the last successful upload wrote into
  // the bucket's _manifest.json.
  const remoteManifest = await loadRemoteManifest({ ...env, bucket })

  // Seed the new manifest with the remote one so partial builds (e.g.
  // BG_EFFECT=silk vite build, which produces a single chunk in dist/)
  // do NOT shrink the published manifest. Without this seed, every
  // partial build would drop all non-rebuilt slugs from the manifest,
  // even though their objects still live in the bucket — push Edge
  // Functions would then return chunk_hash=null for those slugs and
  // lose integrity tracking + skip-if-same. Local fresh hashes still
  // overwrite the remote entries inside the loop below.
  const manifest = { ...remoteManifest }
  let uploaded = 0
  let skipped = 0

  for (const chunk of chunks) {
    const sha = sha256Of(chunk.path)
    manifest[chunk.slug] = sha
    const path = chunk.name

    if (remoteManifest[chunk.slug] === sha) {
      console.log(`  ✓ ${chunk.slug.padEnd(24)} up-to-date (${sha.slice(7, 19)}…)`)
      skipped++
      continue
    }

    if (dryRun) {
      console.log(`  ↑ ${chunk.slug.padEnd(24)} would upload (${(chunk.size / 1024).toFixed(1)} KB)`)
      uploaded++
      continue
    }

    const body = readFileSync(chunk.path)
    await putChunk({
      ...env,
      bucket,
      path,
      body,
      contentType: 'application/javascript; charset=utf-8',
    })
    console.log(`  ↑ ${chunk.slug.padEnd(24)} uploaded (${(chunk.size / 1024).toFixed(1)} KB, ${sha.slice(7, 19)}…)`)
    uploaded++
  }

  // Manifest: write both locally (committed) and to the bucket (read by EFs
  // at cold-start + by the next upload run for idempotency).
  const distDir = 'dist'
  if (!existsSync(distDir)) mkdirSync(distDir, { recursive: true })
  const manifestPath = join(distDir, `${kind}-chunks-manifest.json`)
  const manifestJson = JSON.stringify(manifest, null, 2) + '\n'
  writeFileSync(manifestPath, manifestJson, 'utf8')
  console.log(`[upload-chunks] wrote ${manifestPath}`)

  if (!dryRun) {
    try {
      await putChunk({
        ...env,
        bucket,
        path: '_manifest.json',
        body: manifestJson,
        contentType: 'application/json; charset=utf-8',
      })
      console.log(`[upload-chunks] wrote ${bucket}/_manifest.json`)
    } catch (err) {
      console.error(`[upload-chunks] ERROR writing bucket manifest: ${err.message}`)
      process.exit(2)
    }
  }

  console.log(`[upload-chunks] done — uploaded=${uploaded} skipped=${skipped} total=${chunks.length}`)
}

main().catch((err) => {
  console.error(`[upload-chunks] FATAL: ${err.stack || err.message}`)
  process.exit(2)
})
