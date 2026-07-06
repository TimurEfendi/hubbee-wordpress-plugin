// Defensive config normalizers for vendor components.
//
// The SaaS Preview applies hex→RGB and hex→numeric conversions in
// `src/pages/asset-library/components/BackgroundPreview.tsx:mapConfigToProps`
// before rendering. The WP runtime (`WP_hubbee/src/backgrounds/_runtime/index.ts`)
// passes raw JSON from `data-bz-bg-config` straight to the vendor component,
// so vendor components must accept both shapes (Hex strings and the
// component's native numeric form) to render identically in Preview and Live.
//
// These helpers let each vendor component declare a tolerant prop type
// without pulling in the SaaS-only mapConfigToProps.

function hexStringToRgb01(hex: string): [number, number, number] {
  const h = hex.replace('#', '').trim()
  if (h.length === 3) {
    return [
      parseInt(h[0] + h[0], 16) / 255,
      parseInt(h[1] + h[1], 16) / 255,
      parseInt(h[2] + h[2], 16) / 255,
    ]
  }
  if (h.length >= 6) {
    return [
      parseInt(h.slice(0, 2), 16) / 255,
      parseInt(h.slice(2, 4), 16) / 255,
      parseInt(h.slice(4, 6), 16) / 255,
    ]
  }
  return [1, 1, 1]
}

function hexStringToNumeric(hex: string): number {
  const h = hex.replace('#', '').trim()
  const parsed = parseInt(h, 16)
  return Number.isNaN(parsed) ? 0 : parsed
}

/**
 * Accepts `[r, g, b]` (0-1 each) or a hex string (`#RRGGBB` / `#RGB`).
 * Returns a normalized `[r, g, b]` tuple. On invalid input, returns `fallback`.
 */
export function normalizeColor(
  input: unknown,
  fallback: [number, number, number] = [1, 1, 1],
): [number, number, number] {
  if (Array.isArray(input) && input.length >= 3 &&
    typeof input[0] === 'number' && typeof input[1] === 'number' && typeof input[2] === 'number') {
    return [input[0], input[1], input[2]]
  }
  if (typeof input === 'string' && input.length > 0 && input.startsWith('#')) {
    return hexStringToRgb01(input)
  }
  return fallback
}

/**
 * Accepts an array of numeric color values (`0xRRGGBB`) or an array of hex
 * strings. Returns an array of numeric color values. Unknown entries become 0.
 */
export function normalizeColorArray(input: unknown): number[] {
  if (!Array.isArray(input)) return []
  return input.map((c) => {
    if (typeof c === 'number') return c
    if (typeof c === 'string' && c.startsWith('#')) return hexStringToNumeric(c)
    return 0
  })
}
