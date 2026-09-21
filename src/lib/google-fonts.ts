/**
 * Google Fonts loader for WP live bundle.
 *
 * Lightweight, non-React utility that:
 *  1. Loads Google Font CSS via <link> injection (deduped by ID)
 *  2. Waits for the font to be usable via document.fonts.load()
 *  3. Normalises legacy short font names to full CSS stacks
 *
 * Ported from src/lib/fonts.ts + src/hooks/useGoogleFont.ts
 */

// ── Font registry (mirrors src/lib/fonts.ts) ──────────────────────────

interface FontEntry {
  value: string
  label: string
  category: 'system' | 'google'
  googleFamily?: string
  weights?: number[]
}

const SYSTEM_FONTS: FontEntry[] = [
  { value: 'system-ui, -apple-system, sans-serif', label: 'System Default', category: 'system' },
  { value: 'Arial, Helvetica, sans-serif', label: 'Arial', category: 'system' },
  { value: 'Helvetica Neue, Helvetica, Arial, sans-serif', label: 'Helvetica', category: 'system' },
  { value: 'Georgia, Times New Roman, serif', label: 'Georgia', category: 'system' },
  { value: 'Verdana, Geneva, sans-serif', label: 'Verdana', category: 'system' },
  { value: 'Trebuchet MS, sans-serif', label: 'Trebuchet MS', category: 'system' },
  { value: 'Tahoma, Geneva, sans-serif', label: 'Tahoma', category: 'system' },
  { value: 'Times New Roman, Times, serif', label: 'Times New Roman', category: 'system' },
  { value: 'Courier New, Courier, monospace', label: 'Courier New', category: 'system' },
]

const GOOGLE_FONTS: FontEntry[] = [
  { value: 'Inter, sans-serif', label: 'Inter', category: 'google', googleFamily: 'Inter' },
  { value: 'Roboto, sans-serif', label: 'Roboto', category: 'google', googleFamily: 'Roboto' },
  { value: 'Open Sans, sans-serif', label: 'Open Sans', category: 'google', googleFamily: 'Open+Sans' },
  { value: 'Lato, sans-serif', label: 'Lato', category: 'google', googleFamily: 'Lato' },
  { value: 'Montserrat, sans-serif', label: 'Montserrat', category: 'google', googleFamily: 'Montserrat' },
  { value: 'Poppins, sans-serif', label: 'Poppins', category: 'google', googleFamily: 'Poppins' },
  { value: 'Raleway, sans-serif', label: 'Raleway', category: 'google', googleFamily: 'Raleway' },
  { value: 'Nunito, sans-serif', label: 'Nunito', category: 'google', googleFamily: 'Nunito' },
  { value: 'Playfair Display, serif', label: 'Playfair Display', category: 'google', googleFamily: 'Playfair+Display' },
  { value: 'Merriweather, serif', label: 'Merriweather', category: 'google', googleFamily: 'Merriweather' },
  { value: 'Source Sans 3, sans-serif', label: 'Source Sans 3', category: 'google', googleFamily: 'Source+Sans+3' },
  { value: 'PT Sans, sans-serif', label: 'PT Sans', category: 'google', googleFamily: 'PT+Sans' },
  { value: 'Oswald, sans-serif', label: 'Oswald', category: 'google', googleFamily: 'Oswald' },
  { value: 'Nunito Sans, sans-serif', label: 'Nunito Sans', category: 'google', googleFamily: 'Nunito+Sans' },
  { value: 'Rubik, sans-serif', label: 'Rubik', category: 'google', googleFamily: 'Rubik' },
  { value: 'Work Sans, sans-serif', label: 'Work Sans', category: 'google', googleFamily: 'Work+Sans' },
  { value: 'Fira Sans, sans-serif', label: 'Fira Sans', category: 'google', googleFamily: 'Fira+Sans' },
  { value: 'Barlow, sans-serif', label: 'Barlow', category: 'google', googleFamily: 'Barlow' },
  { value: 'DM Sans, sans-serif', label: 'DM Sans', category: 'google', googleFamily: 'DM+Sans' },
  { value: 'Mulish, sans-serif', label: 'Mulish', category: 'google', googleFamily: 'Mulish' },
  { value: 'Quicksand, sans-serif', label: 'Quicksand', category: 'google', googleFamily: 'Quicksand' },
  { value: 'Josefin Sans, sans-serif', label: 'Josefin Sans', category: 'google', googleFamily: 'Josefin+Sans' },
  { value: 'Libre Baskerville, serif', label: 'Libre Baskerville', category: 'google', googleFamily: 'Libre+Baskerville' },
  { value: 'Crimson Text, serif', label: 'Crimson Text', category: 'google', googleFamily: 'Crimson+Text' },
  { value: 'Space Grotesk, sans-serif', label: 'Space Grotesk', category: 'google', googleFamily: 'Space+Grotesk' },
]

const ALL_FONTS: FontEntry[] = [...SYSTEM_FONTS, ...GOOGLE_FONTS]

// ── Legacy name → full CSS stack mapping ───────────────────────────────

const LEGACY_MAP: Record<string, string> = {}
for (const font of ALL_FONTS) {
  LEGACY_MAP[font.label] = font.value
  const firstName = font.value.split(',')[0].trim()
  if (firstName !== font.label) {
    LEGACY_MAP[firstName] = font.value
  }
}
LEGACY_MAP['system-ui'] = 'system-ui, -apple-system, sans-serif'
LEGACY_MAP['Helvetica, Arial'] = 'Helvetica Neue, Helvetica, Arial, sans-serif'

/**
 * Maps legacy short font values (e.g. "Quicksand") to the full CSS stack
 * ("Quicksand, sans-serif"). Returns the value unchanged if already normalised
 * or not recognised.
 */
export function normalizeFontValue(value: string | undefined): string | undefined {
  if (!value) return value
  if (ALL_FONTS.some(f => f.value === value)) return value
  return LEGACY_MAP[value] ?? value
}

// ── Font loader ────────────────────────────────────────────────────────

function getFontEntry(value: string): FontEntry | undefined {
  return ALL_FONTS.find(f => f.value === value)
}

function buildGoogleFontUrl(entry: FontEntry): string {
  const weights = entry.weights ?? [400, 700]
  return `https://fonts.googleapis.com/css2?family=${entry.googleFamily}:wght@${weights.join(';')}&display=swap`
}

/** Fonts that have already been fully loaded in this session */
const loaded = new Set<string>()

/** In-flight load promises, keyed by normalised font value */
const pending = new Map<string, Promise<void>>()

/**
 * Loads a Google Font by injecting a <link> and waiting for
 * `document.fonts.load()`.  Returns a promise that resolves once the
 * font is usable for rendering (both CSS and Canvas 2D).
 *
 * System fonts and already-loaded fonts resolve immediately.
 * Concurrent calls for the same font share a single promise.
 */
export function loadGoogleFont(fontValue: string | undefined): Promise<void> {
  if (!fontValue) return Promise.resolve()

  // Normalise first so we can look up by full value
  const normalised = normalizeFontValue(fontValue) ?? fontValue
  const entry = getFontEntry(normalised)
  if (!entry || entry.category === 'system') return Promise.resolve()
  if (loaded.has(normalised)) return Promise.resolve()

  // Share in-flight promise
  const inflight = pending.get(normalised)
  if (inflight) return inflight

  const promise = (async () => {
    // 1. Inject <link> if not already present
    const linkId = `gfont-${entry.googleFamily}`
    if (!document.getElementById(linkId)) {
      const link = document.createElement('link')
      link.id = linkId
      link.rel = 'stylesheet'
      link.href = buildGoogleFontUrl(entry)
      link.dataset.gfont = entry.googleFamily
      document.head.appendChild(link)
    }

    // 2. Wait for browser to make the font usable. FontFaceSet is missing in
    //    some embedded/legacy engines (and JSDOM) — the <link> above still
    //    applies the font there, we just can't await readiness.
    if (typeof document.fonts?.load === 'function') {
      const familyName = entry.label
      const weights = entry.weights ?? [400, 700]
      await Promise.all(
        weights.map(w => document.fonts.load(`${w} 16px "${familyName}"`))
      )
    }

    loaded.add(normalised)
  })()

  pending.set(normalised, promise)
  promise.finally(() => pending.delete(normalised))

  return promise
}
