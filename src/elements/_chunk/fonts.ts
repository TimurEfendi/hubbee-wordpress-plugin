/**
 * Chunk-side font preloading.
 *
 * The editor preview loads Google Fonts through `useGoogleFont` (wired into
 * FontSelectField), so a configured webfont always renders in the SaaS. On a
 * WordPress page nothing loaded the font asset — the CSS `font-family`
 * declaration arrived, but the family silently degraded to its fallback
 * (parity bug class: option visible in preview, dead on WP).
 *
 * `ensureConfigFonts` scans the flat element config for every font-bearing
 * key and delegates to `loadGoogleFont` (idempotent, system-font-aware).
 * It resolves once all referenced webfonts are usable, so canvas/WebGL
 * renderers that rasterize text at mount (e.g. CircularGallery) draw with
 * the real font instead of the fallback.
 */

import { loadGoogleFont } from '../../lib/google-fonts';

/** Flat-config keys that carry a font-stack value anywhere in the element set. */
const FONT_KEYS = [
  'headlineFont',
  'itemFont',
  'bodyFont',
  'titleFont',
  'descriptionFont',
  'overlayFont',
  'font',
] as const;

export function ensureConfigFonts(cfg: Record<string, unknown>): Promise<void> {
  const loads: Promise<void>[] = [];
  for (const key of FONT_KEYS) {
    const value = cfg[key];
    if (typeof value === 'string' && value.length > 0) {
      loads.push(loadGoogleFont(value).catch(() => undefined));
    }
  }
  return loads.length > 0 ? Promise.all(loads).then(() => undefined) : Promise.resolve();
}
