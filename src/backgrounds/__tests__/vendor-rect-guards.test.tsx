/**
 * Vendor-component rect-guard tripwire (2026-07-24, DotGrid incident).
 *
 * Bug class: vendored ReactBits components read `ref.current!.getBoundingClientRect()`
 * inside window/document listeners, rAF or observer callbacks. The `!` assertion
 * compiles away and most vendor files are `@ts-nocheck`, so nothing flags it.
 * At runtime the race is real: on unmount React nulls the ref synchronously in
 * the commit, but the `removeEventListener` cleanup is a passive effect flushed
 * a task later — a mousemove/click landing in that gap dereferences null and
 * throws an uncatchable (for error boundaries) TypeError. The asset-library
 * debounce-remount (useDebouncedRemountKey) turns every config edit into such
 * an unmount, which is how DotGrid took down the background configurator:
 *   "Uncaught TypeError: Cannot read properties of null (reading 'getBoundingClientRect')"
 *
 * Fix pattern (mirrors LetterGlitch): capture the ref into a local and guard —
 *   const el = someRef.current;
 *   if (!el) return;
 *   const rect = el.getBoundingClientRect();
 *
 * Test 1 statically bans direct `.current.getBoundingClientRect()` /
 * `.current!.getBoundingClientRect()` across ALL vendored WP_hubbee sources, so
 * a future 1:1 upstream re-sync that drops the guards goes red immediately.
 * (`.current?.getBoundingClientRect()` stays allowed — it is runtime-safe.)
 *
 * Test 2 encodes the race behaviourally: it captures the window listeners
 * DotGrid registers, unmounts, then invokes the captured handlers directly —
 * exactly what a queued input event does in the ref-nulled/listener-alive gap.
 */
import { describe, it, expect, vi } from 'vitest'
import { readdirSync, readFileSync } from 'fs'
import { resolve, relative } from 'path'
import { render } from '@testing-library/react'
import DotGrid from '../vendor-components/DotGrid'

const REPO = resolve(__dirname, '../../../..')
const WP_SRC = resolve(REPO, 'WP_hubbee/src')

const sourceFiles = (dir: string): string[] =>
  readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
    if (entry.name === '__tests__' || entry.name === 'node_modules') return []
    const full = resolve(dir, entry.name)
    if (entry.isDirectory()) return sourceFiles(full)
    return /\.(ts|tsx)$/.test(entry.name) ? [full] : []
  })

describe('vendor rect-guard tripwire', () => {
  it('no direct ref.current(.!)getBoundingClientRect deref in WP_hubbee sources', () => {
    const banned = /\.current!?\.getBoundingClientRect/
    const violations = sourceFiles(WP_SRC).flatMap(file =>
      readFileSync(file, 'utf8')
        .split('\n')
        .map((line, i) => ({ line, i }))
        .filter(({ line }) => banned.test(line))
        .map(({ line, i }) => `${relative(REPO, file)}:${i + 1}: ${line.trim()}`)
    )
    expect(
      violations,
      'getBoundingClientRect must never be called directly on a ref. Window/document ' +
        'listeners, rAF and observer callbacks can fire after unmount, when React has ' +
        'already nulled the ref but the passive-effect cleanup has not removed the ' +
        'listener yet (DotGrid configurator crash, 2026-07). Capture a local instead: ' +
        'const el = ref.current; if (!el) return; el.getBoundingClientRect(). ' +
        'Re-apply the guards when re-syncing a vendor component 1:1 from upstream.'
    ).toEqual([])
  })

  it('DotGrid window handlers survive events arriving after unmount', () => {
    const captured: Array<{ type: string; fn: EventListener }> = []
    const original = window.addEventListener.bind(window)
    const spy = vi
      .spyOn(window, 'addEventListener')
      .mockImplementation((type: string, fn: any, opts?: any) => {
        captured.push({ type, fn })
        return original(type, fn, opts)
      })

    const { unmount } = render(<DotGrid />)
    const move = captured.find(l => l.type === 'mousemove')
    const click = captured.find(l => l.type === 'click')
    spy.mockRestore()

    expect(move, 'DotGrid must register its window mousemove listener').toBeTruthy()
    expect(click, 'DotGrid must register its window click listener').toBeTruthy()

    unmount() // refs are nulled here; in the real race the listeners are still attached

    expect(() => {
      move!.fn(new MouseEvent('mousemove', { clientX: 12, clientY: 34 }))
      click!.fn(new MouseEvent('click', { clientX: 12, clientY: 34 }))
    }).not.toThrow()
  })
})
