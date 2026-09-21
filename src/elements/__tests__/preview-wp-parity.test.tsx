/**
 * Preview↔WP-frontend parity guards (2026-07-23 audit).
 *
 * Bug class: an option configured in the SaaS shows its effect in the editor
 * preview but is silently dead on the published WordPress page, because
 * preview (ElementPreview.tsx) and WP chunk (effects/<slug>/index.tsx) are
 * separate render paths. Push reports success either way.
 *
 * These tests pin the drift-prone joints:
 *  1. every registry element type has a WP chunk entry (flying-posters class)
 *  2. ElementHeadlineFrame membership: chunk truth == HEADLINE_FRAME_ELEMENTS
 *     == what the preview derives its headline branch from
 *  3. every headline mapper forwards the full headline key group
 *  4. ElementHeadlineFrame renders color/typography into the DOM
 *  5. chunk-side webfont preloading covers all font-bearing config keys
 */
import { describe, it, expect } from 'vitest'
import '@testing-library/jest-dom'
import { readdirSync, readFileSync } from 'fs'
import { resolve } from 'path'
import { render, screen } from '@testing-library/react'
import {
  HEADLINE_FRAME_ELEMENTS,
  sectionToVendorProps,
} from '@hubbee-shared/elements/section-to-vendor'
import ElementHeadlineFrame from '@hubbee-saas/components/ElementHeadlineFrame'
import { ensureConfigFonts } from '../_chunk/fonts'

const REPO = resolve(__dirname, '../../../..')
const EFFECTS_DIR = resolve(REPO, 'WP_hubbee/src/elements/effects')
const REGISTRY = resolve(REPO, 'src/pages/asset-library/element-types/registry.ts')
const PREVIEW = resolve(REPO, 'src/pages/asset-library/components/ElementPreview.tsx')

const chunkSlugs = (): string[] => readdirSync(EFFECTS_DIR, { withFileTypes: true })
  .filter(d => d.isDirectory())
  .map(d => d.name)
  .sort()

const registryTypes = (): string[] => {
  const src = readFileSync(REGISTRY, 'utf8')
  return [...src.matchAll(/^    type: '([a-z-]+)',$/gm)].map(m => m[1]).sort()
}

const chunkSource = (slug: string): string =>
  readFileSync(resolve(EFFECTS_DIR, slug, 'index.tsx'), 'utf8')

describe('registry ↔ chunk coverage', () => {
  it('every registry element type has a WP chunk entry (and vice versa)', () => {
    // flying-posters shipped configurable in the SaaS with NO chunk at all —
    // the published page had no renderer. This pins full coverage both ways.
    expect(chunkSlugs()).toEqual(registryTypes())
  })
})

describe('ElementHeadlineFrame membership (single source: HEADLINE_FRAME_ELEMENTS)', () => {
  const expected = [...HEADLINE_FRAME_ELEMENTS].sort()

  it('exactly the listed chunks wrap ElementHeadlineFrame', () => {
    const wrapping = chunkSlugs().filter(slug => chunkSource(slug).includes('ElementHeadlineFrame'))
    expect(wrapping.sort()).toEqual(expected)
  })

  it.each([...HEADLINE_FRAME_ELEMENTS])('%s chunk forwards the full headline prop group', (slug) => {
    const src = chunkSource(slug)
    for (const prop of ['headline', 'headlineFont', 'headlineFontSize', 'headlineColor', 'headerGap']) {
      expect(src, `${slug} chunk must pass ${prop} to ElementHeadlineFrame`).toContain(prop)
    }
  })

  it('the editor preview derives its headline branch from the shared set', () => {
    // The preview must import HEADLINE_FRAME_ELEMENTS — a hardcoded slug list
    // there is exactly how circular-gallery got skipped in 82b68d4.
    expect(readFileSync(PREVIEW, 'utf8')).toContain('HEADLINE_FRAME_ELEMENTS')
  })

  it.each([...HEADLINE_FRAME_ELEMENTS])('%s mapper forwards the headline key group', (slug) => {
    const props = sectionToVendorProps(slug, {
      headline: 'PARITY',
      headlineFont: 'Inter, sans-serif',
      headlineFontSize: 32,
      headlineColor: '#123456',
      headerGap: 24,
      items: [{ id: '1', image: { url: 'https://example.com/a.jpg', alt: 'a' }, text: 'A' }],
    })
    expect(props.headline).toBe('PARITY')
    expect(props.headlineFont).toBe('Inter, sans-serif')
    expect(props.headlineFontSize).toBe(32)
    expect(props.headlineColor).toBe('#123456')
    expect(props.headerGap).toBe(24)
  })
})

describe('ElementHeadlineFrame rendering', () => {
  it('renders headline text with configured color/typography', () => {
    render(
      <ElementHeadlineFrame
        headline="Our Work"
        headlineFont="Georgia, Times New Roman, serif"
        headlineFontSize={32}
        headlineColor="#374151"
        headerGap={20}
      >
        <div data-testid="child" />
      </ElementHeadlineFrame>,
    )
    const h2 = screen.getByRole('heading', { level: 2 })
    expect(h2).toHaveTextContent('Our Work')
    expect(h2.style.color).toBe('rgb(55, 65, 81)')
    expect(h2.style.fontFamily).toContain('Georgia')
    expect(h2.style.fontSize).toBe('32px')
    expect(screen.getByTestId('child')).toBeInTheDocument()
  })

  it('renders no heading block for an empty/whitespace headline', () => {
    render(
      <ElementHeadlineFrame headline="   ">
        <div />
      </ElementHeadlineFrame>,
    )
    expect(screen.queryByRole('heading')).toBeNull()
  })

  it('defaults match the editor preview (white, 48px, Arial)', () => {
    render(
      <ElementHeadlineFrame headline="T">
        <div />
      </ElementHeadlineFrame>,
    )
    const h2 = screen.getByRole('heading', { level: 2 })
    expect(h2.style.color).toBe('rgb(255, 255, 255)')
    expect(h2.style.fontSize).toBe('48px')
    expect(h2.style.fontFamily).toBe('Arial')
  })
})

describe('chunk-side webfont preloading', () => {
  it('injects a Google Fonts <link> for every font-bearing config key', async () => {
    await ensureConfigFonts({
      headlineFont: 'Poppins, sans-serif',
      itemFont: 'Playfair Display, serif',
      font: 'Space Grotesk, sans-serif',
    })
    expect(document.getElementById('gfont-Poppins')).toBeTruthy()
    expect(document.getElementById('gfont-Playfair+Display')).toBeTruthy()
    expect(document.getElementById('gfont-Space+Grotesk')).toBeTruthy()
  })

  it('resolves immediately for system fonts without injecting anything', async () => {
    const before = document.querySelectorAll('link[data-gfont]').length
    await ensureConfigFonts({ headlineFont: 'Arial, Helvetica, sans-serif' })
    expect(document.querySelectorAll('link[data-gfont]').length).toBe(before)
  })

  it('normalises legacy short names (e.g. "Poppins") to the full stack', async () => {
    await ensureConfigFonts({ bodyFont: 'Quicksand' })
    expect(document.getElementById('gfont-Quicksand')).toBeTruthy()
  })

  it('every chunk entry registers through the font-preloading register helper', () => {
    for (const slug of chunkSlugs()) {
      expect(chunkSource(slug), `${slug} must register via registerElementChunk`)
        .toContain('registerElementChunk')
    }
  })
})
