// Sanitization helpers for HTML/SVG content coming from user-controlled
// asset configs. Every `dangerouslySetInnerHTML` target in the live-sections
// bundle must go through one of these — see audit P0-3.
//
// The push pipeline (SaaS → bg-push/component-push/text-effect-push →
// WordPress) carries raw JSON. If a workspace member sets a config field
// like `block.content = "<img src=x onerror=fetch(...)>"`, the unsanitised
// dangerouslySetInnerHTML would execute it at render time on every site
// where that asset is installed. DOMPurify removes script vectors while
// preserving the rich-text structure the designers actually use.

import DOMPurify, { type Config } from 'dompurify'

// Rich text (headlines, paragraph body). Allows common inline formatting
// but forbids <script>, event handlers, javascript: URLs, <iframe>, etc.
const RICH_TEXT_CONFIG: Config = {
  ALLOWED_TAGS: [
    'p', 'br', 'div', 'span',
    'strong', 'b', 'em', 'i', 'u', 's', 'mark', 'small',
    'a',
    'ul', 'ol', 'li',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'blockquote', 'q', 'code', 'pre',
    'sub', 'sup',
  ],
  ALLOWED_ATTR: ['href', 'target', 'rel', 'class', 'style', 'title'],
  ALLOWED_URI_REGEXP: /^(?:(?:https?|mailto|tel):|#|\/)/i,
}

// Icons ship as inline SVG in many configs. Strict allowlist — no foreignObject,
// no event handlers, no script-bearing attributes. sanitize() returns a string
// that is safe to drop into dangerouslySetInnerHTML.
const ICON_CONFIG: Config = {
  ALLOWED_TAGS: [
    'svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use',
    'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon',
    'linearGradient', 'radialGradient', 'stop',
    'filter', 'feGaussianBlur', 'feOffset', 'feMerge', 'feMergeNode',
    'clipPath', 'mask', 'pattern',
  ],
  ALLOWED_ATTR: [
    'viewBox', 'xmlns', 'fill', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin',
    'stroke-miterlimit', 'stroke-opacity', 'stroke-dasharray', 'stroke-dashoffset',
    'fill-rule', 'clip-rule', 'fill-opacity', 'opacity',
    'd', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
    'width', 'height', 'transform', 'id', 'class',
    'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform',
    'points', 'preserveAspectRatio',
  ],
}

export function sanitizeRichText(html: unknown): string {
  if (html == null) return ''
  // DOMPurify v3 returns `string | TrustedHTML` depending on the platform's
  // Trusted Types support; we always feed plain string into
  // `dangerouslySetInnerHTML`, so coerce here.
  return String(DOMPurify.sanitize(String(html), RICH_TEXT_CONFIG))
}

export function sanitizeIcon(html: unknown): string {
  if (html == null) return ''
  return String(DOMPurify.sanitize(String(html), ICON_CONFIG))
}
