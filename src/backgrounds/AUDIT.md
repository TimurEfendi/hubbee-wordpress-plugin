# Vendor-Component Lifecycle Audit (2026-07-22)

Status snapshot of all WebGL/Canvas/DOM vendor components with respect to the
preview keying strategy in `BackgroundPreview` / `ElementPreview` /
`TextEffectPreview`. Supersedes the 2026-04-29 snapshot, which had drifted
badly (it documented 8 of 21 elements, 5 of 21 text effects, and contradicted
the code on ascii-text and liquid-ether). Basis: full per-option reactivity
audit of all 81 components (2026-07-21) plus the dead-option fix pass.

## The three mechanisms

1. **Prop-reactive (type-keyed).** Types in `PROP_REACTIVE_*_TYPES` never
   remount on config edits (backgrounds/text-effects: `key = type`; elements:
   `key = structuralKey`, which changes only on item add/remove/reorder).
   Every option must flow live through props (propsRef pattern, React
   reconciliation, or reactive useEffect deps).

2. **Setup-only keys (`SETUP_ONLY_CONFIG_KEYS`).** Prop-reactive types can
   still have a handful of options consumed exactly once at mount
   (entry-animation params, `useState` initializers, `useRef` snapshots).
   Those option paths are hashed into the remount key
   (`useSetupOnlyHash`) — changing one triggers a single debounced remount
   that replays the entry animation with the new values, while all other
   options keep flowing live.

3. **Debounce bridge with FROZEN props (`useDebouncedRemount`).** Non-listed
   types key on the full config hash, debounced ~300 ms. Their props are
   frozen to the debounced key: the key alone is not enough, because live
   props would still re-trigger the components' value-dep setup effects per
   keystroke (WebGL rebuild churn through the back door). A non-reactive
   component sees exactly one atomic key+props update per idle window.

The reference prop-reactive pattern is `Aurora.tsx`:

```tsx
const propsRef = useRef(props)
propsRef.current = props      // refreshed every render

useEffect(() => {
  // setup once: renderer / scene / shader
  const update = (t) => {
    const { speed, color, ... } = propsRef.current  // ← live read
    // apply to uniforms, render
  }
  // RAF loop driven by `update`
  return () => cleanup()
}, [])
```

## Backgrounds (39 total — 22 prop-reactive, 17 debounced)

### ✅ Prop-reactive (22)

| Component | Pattern | Notes |
|-----------|---------|-------|
| Aurora | propsRef | reference implementation |
| Ballpit | propsRef + 500 ms config-sync interval | 3D simulation; samples sim.options between frames |
| Beams | R3F native | `<Canvas>` reconciles props automatically |
| ColorBends | propsRef + dedicated update useEffect | OGL shader |
| Dither | R3F native | `<Canvas>` |
| FaultyTerminal | propsRef | OGL shader, full-frame uniform sync |
| FloatingLines | propsRef | three.js shader |
| Galaxy | propsRef | OGL shader; live colour + uniform updates |
| GridDistortion | propsRef + setup-only deps `[grid, imageSrc]` | three.js |
| GridMotion | DOM/GSAP | no WebGL context |
| Iridescence | propsRef | OGL shader |
| LightPillar | multi-useEffect (per-prop updates) | three.js |
| LightRays | multi-useEffect | OGL shader |
| Lightning | propsRef | raw WebGL |
| LiquidChrome | propsRef | OGL shader |
| Orb | propsRef | OGL shader |
| PixelBlast | conditional-reinit (only `antialias`/`liquid`/`noiseAmount`); else uniform updates | three.js + postprocessing |
| Plasma | propsRef | OGL shader |
| Radar | propsRef | OGL shader |
| RippleGrid | multi-useEffect | OGL shader |
| SoftAurora | propsRef | OGL shader |
| Threads | propsRef | OGL shader |

All listed components register a `webglcontextlost` listener and call
`gl.getExtension('WEBGL_lose_context')?.loseContext()` on unmount, so the
GPU side releases reliably even if the JS GC is slow.

### 🔄 Debounce bridge (17) — frozen props, single remount per idle window

| Component | Reason |
|-----------|--------|
| Silk | **R3F but NOT prop-reactive:** shaderMaterial uniforms object is rebuilt via useMemo, but three.js only re-reads uniforms via in-place `.value` writes and Silk's `useFrame` bumps `uTime` only — every option is frozen at material compile. Was wrongly in the prop-reactive set until 2026-07-22 (all 5 options dead in the editor). |
| LiquidEther | propsRef exists, but the sim builds FBOs sized `resolution × Common.width` and is StrictMode-double-mount-sensitive — intentionally kept on the bridge |
| Hyperspeed | 1249-LoC effect-options driven setup; rebuild pipeline not incremental |
| Waves | configRef per-frame reads for 8 options, but xGap/yGap only consumed in mount/resize `setLines()` |
| Particles | single-useEffect rebuild on 11 deps; `particleColors` used at mount but missing from deps |
| Balatro, DarkVeil, DotGrid, EvilEye, GradientBlinds, Grainient, LetterGlitch, LineWaves, PixelSnow, Prism, PrismaticBurst, ShapeGrid | Single-useEffect with multi-prop deps; slated for propsRef migration |

## Elements (21 total — 14 prop-reactive, 7 debounced)

| Component | Keying | Notes |
|-----------|--------|-------|
| Masonry | prop-reactive + setup-only `design.ease/duration/stagger/animateFrom/blurToFocus` | GSAP entry animation runs only in the `!hasMounted` branch |
| Stepper | prop-reactive + setup-only `design.initialStep` | `useState` initializer |
| AnimatedList | prop-reactive + setup-only `design.stagger` | AnimatePresence entry transition |
| DomeGallery, ChromaGrid, FlowingMenu, InfiniteMenu, BounceCards, TiltedCard, BorderGlow, SpotlightCard, PixelCard, Carousel, MagicBento | prop-reactive | DOM/GSAP/CSS/Canvas2D — props flow through React reconciliation (PixelCard's grid reinits on its `[gap, speed, colors, noFocus]` deps) |
| CircularGallery | debounced | OGL, single-useEffect with massive deps |
| CardSwap | debounced | GSAP timeline pinned to refs at mount |
| DecayCard | debounced | rAF loop captures props at mount |
| ProfileCard | debounced | tilt engine state cached at mount |
| Stack | debounced | motion drag-state internal |
| ScrollStack | debounced | Lenis instance bound to scroller |
| FlyingPosters | debounced | OGL, full rebuild on any dep; `hidden: true`, no WP chunk |

## Text effects (21 total — 18 prop-reactive, 3 debounced)

| Component | Keying | Notes |
|-----------|--------|-------|
| CurvedLoop | prop-reactive + setup-only `direction` | `useRef(direction)` snapshot never re-synced |
| BlurText | prop-reactive + setup-only `delay/stepDuration/threshold` | entry animation latches on first IntersectionObserver hit |
| TextPressure, CircularText, DecryptedText, FallingText, FuzzyText, GlitchText, GradientText, RotatingText, ScrambledText, ScrollFloat, ScrollReveal, ScrollVelocity, ShinyText, TextType, TrueFocus, VariableProximity | prop-reactive | DOM/SVG/Canvas2D/CSS |
| ASCIIText | debounced | **three.js renderer rebuilt by its setup effect on any of 6 value deps** — was wrongly in the prop-reactive set until 2026-07-22 (un-debounced per-keystroke renderer teardown). Frozen props limit it to one rebuild per idle window. |
| Shuffle, SplitText | debounced | GSAP entry animations set completion flags after first play — need the remount to reflect edits |

## Headline numbers (2026-07-22)

- Backgrounds: **22 / 39 prop-reactive** (56%), 17 debounced
- Elements: **14 / 21 prop-reactive** (67%), 7 debounced
- Text effects: **18 / 21 prop-reactive** (86%), 3 debounced
- **Overall: 54 / 81 prop-reactive (67%)** — and since the 2026-07 fix pass,
  **every configurator option updates the preview** (live, via setup-key
  remount, or via the debounce bridge). No dead options remain.

## Preview↔WP-frontend parity (elements) — audit 2026-07-23

The 2026-07-22 pass covered reactivity INSIDE the preview. The other axis —
"the preview shows it, the published WordPress page silently doesn't" — was
audited 2026-07-23 for all 21 element types (trigger: circular-gallery
headline color/typography configured + pushed green, never rendered on WP).

**Confirmed dead-on-WP and fixed (branch `fix/element-preview-wp-parity`):**

| Type | Break | Fix |
|------|-------|-----|
| circular-gallery | chunk missed `ElementHeadlineFrame` (skipped in 82b68d4) → headline+color+typography never rendered | chunk wraps frame like the other galleries |
| flying-posters | configurable+pushable in the SaaS with NO chunk anywhere (repo + storage manifest) | chunk entry created (bare vendor, shared mapper) |
| ALL types with a FontSelectField | Google-font values degraded to fallback fonts on WP — preview loads fonts via `useGoogleFont`, WP never loaded the asset (`WP_hubbee/src/lib/google-fonts.ts` had ZERO callers) | `registerElementChunk` now preloads all font-bearing config keys via `_chunk/fonts.ts` before mount/update (canvas/WebGL text rasterizes with the real font) |
| border-glow | chunk prop list omitted crop legs (`focalX/focalY/cropZoom/rotation`) → image crop ignored on WP | crop legs forwarded |
| decay-card | chunk cherry-picked `{image,width,height}` + rendered own h3/p children (dropped crop, diverging text-style fallbacks) | chunk passes full mapper output; vendor's internal branch renders (same path as preview) |
| stack | chunk omitted `width`/`height` (sidebar sliders!) → deck size locked to vendor default on WP | forwarded |
| bounce-cards | REVERSE: WP chunk rendered the headline frame, preview never did | preview wraps the same frame |
| dome-gallery | REVERSE: mapper's caption alt-fallback missing in preview → captions appeared only on WP | preview applies the same fallback |
| masonry/chroma-grid/circular-gallery/flowing-menu | absent-key fallback asymmetries (Arial/#ffffff/item filters/link) | mappers+frame+preview aligned |

**Rules (enforced by `WP_hubbee/src/elements/__tests__/preview-wp-parity.test.tsx`):**

1. Every registry element type MUST have `WP_hubbee/src/elements/effects/<slug>/index.tsx`.
2. Headline membership lives in ONE place: `HEADLINE_FRAME_ELEMENTS` in
   `src/lib/elements/section-to-vendor.ts`. The preview derives its headline
   branch from it; the test asserts chunk `ElementHeadlineFrame` usage matches
   it exactly. Adding a headline option = add the slug there AND wrap the chunk.
3. Headline markup renders ONLY through `ElementHeadlineFrame` (preview + chunk)
   — never a hand-written `<h2>`.
4. Whoever adds an option to registry/preview MUST wire the WP chunk path in
   the same PR and verify on a real WP page (frontend markup/screenshot).
   "Push successful / assignment active" proves config DELIVERY, not RENDERING.

## Refactor checklist (per component)

For pending components, follow the Aurora pattern:

1. Introduce `propsRef`:
   ```tsx
   const propsRef = useRef(props)
   propsRef.current = props
   ```
2. Replace direct prop reads inside the RAF loop with `propsRef.current.<x>`.
3. Move setup-only props (uniforms initialised once) to capture the value at
   mount; configuration that should respond live reads from `propsRef`.
   Any prop that stays mount-only MUST be listed in the type's
   `SETUP_ONLY_CONFIG_KEYS` entry — otherwise it is dead in the editor.
4. Confirm cleanup releases the GL context:
   ```tsx
   gl.getExtension('WEBGL_lose_context')?.loseContext()
   // or, for three.js:
   renderer.dispose(); renderer.forceContextLoss()
   ```
5. Add a `webglcontextlost` listener that calls `event.preventDefault()`.
6. Drop the `useEffect` deps array to `[]` (the live values now flow via
   the ref) and remove any
   `// eslint-disable-next-line react-hooks/exhaustive-deps` comments.
7. Add the type to the appropriate `PROP_REACTIVE_*_TYPES` set in
   `src/pages/asset-library/components/{Background,Element,TextEffect}Preview.tsx`.

## Key files

- `src/pages/asset-library/hooks/useDebouncedRemountKey.ts` —
  `useDebouncedRemountKey` (key only), `useDebouncedRemount` (key + frozen
  props payload), `useConfigHash`, `useSetupOnlyHash` (dot-path projection).
- `src/pages/asset-library/components/BackgroundPreview.tsx` — the
  `PROP_REACTIVE_BACKGROUND_TYPES` set and the debounce-bridge fallback.
- `src/pages/asset-library/components/{Element,TextEffect}Preview.tsx` —
  their sets plus per-type `SETUP_ONLY_CONFIG_KEYS`.
- `src/pages/asset-library/components/AssetPreviewBoundary.tsx` — local
  ErrorBoundary that contains preview crashes without reloading the tab.
- `src/lib/webgl/{WebGLContextManager,useWebGLLifecycle,usePropsRef}.ts` —
  shared lifecycle utilities (used by future migrations; existing components
  inline the propsRef pattern directly).
- `src/lib/asset-preview-error.ts` — telemetry helper hooked into
  `log-preview-error` Edge Function and `asset_preview_errors` table.
