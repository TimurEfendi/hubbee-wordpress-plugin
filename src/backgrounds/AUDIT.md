# Vendor-Component Lifecycle Audit (2026-04-29)

Status snapshot of all WebGL/Canvas/DOM vendor components with respect to the
prop-reactive pattern required by `BackgroundPreview` / `ElementPreview` /
`TextEffectPreview` after the Asset Library WebGL stabilisation refactor.

## Why this matters

The preview layer keys components on type only (`key={bgType}` or
`key={effectType}`) when the type is registered in
`PROP_REACTIVE_BACKGROUND_TYPES` / `PROP_REACTIVE_ELEMENT_TYPES` /
`PROP_REACTIVE_TEXT_EFFECT_TYPES`. For unlisted types the preview layer
falls back to a debounced config-hash key (~300 ms idle window) so slider
drags coalesce into a single remount and never blow past the browser's
WebGL-context cap.

The reference pattern is `Aurora.tsx`:

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

## Backgrounds (39 total — 24 prop-reactive, 15 debounced)

### ✅ Prop-reactive (24)

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
| LiquidEther | propsRef + dedicated sim.options sync useEffect | three.js fluid sim |
| Orb | propsRef | OGL shader |
| PixelBlast | conditional-reinit (only `antialias`/`liquid`/`noiseAmount`); else uniform updates | three.js + postprocessing |
| Plasma | propsRef | OGL shader |
| Radar | propsRef | OGL shader |
| RippleGrid | multi-useEffect | OGL shader |
| Silk | R3F native | `<Canvas>` |
| SoftAurora | propsRef | OGL shader |
| Threads | propsRef | OGL shader |

All listed components register a `webglcontextlost` listener and call
`gl.getExtension('WEBGL_lose_context')?.loseContext()` on unmount, so the
GPU side releases reliably even if the JS GC is slow.

### 🔄 Debounced (15) — pending propsRef migration

| Component | Reason |
|-----------|--------|
| Hyperspeed | 1249-LoC effect-options driven setup; webglcontextlost listener added defensively, but the rebuild pipeline is not currently incremental — single rebuild on debounced 300 ms idle is the safer interim |
| Balatro, DarkVeil, DotGrid, EvilEye, GradientBlinds, Grainient, LetterGlitch, LineWaves, Particles, PixelSnow, Prism, PrismaticBurst, ShapeGrid, Waves | Single-useEffect with multi-prop deps; functional via debounce, slated for propsRef migration |

**These do not crash** — the debounced remount key in `BackgroundPreview`
collapses slider drags into one remount per ~300 ms idle window, well
inside the browser's WebGL-context cap. The trade-off is a 300 ms delay
before the preview reflects a config edit.

## Elements (8 total — 7 prop-reactive, 1 debounced)

| Component | Pattern |
|-----------|---------|
| Masonry, DomeGallery, ChromaGrid, FlowingMenu, InfiniteMenu, BounceCards, TiltedCard | DOM/GSAP — props flow through React reconciliation, no WebGL |
| CircularGallery | OGL-based, single-useEffect with massive deps — debounced |

## Text effects (5 total — 4 prop-reactive, 1 debounced)

| Component | Pattern |
|-----------|---------|
| TextPressure, CircularText, DecryptedText, CurvedLoop | DOM/SVG/canvas2D |
| ASCIIText | three.js — debounced |

## Headline numbers (2026-04-29)

- Backgrounds: **24 / 39 prop-reactive** (62%), 15 debounced
- Elements: **7 / 8 prop-reactive** (88%), 1 debounced
- Text effects: **4 / 5 prop-reactive** (80%), 1 debounced
- **Overall: 35 / 52 prop-reactive (67%)**

All 52 components either register a `webglcontextlost` listener (where
applicable) or use the existing R3F lifecycle. None can crash the tab on
slider drag — the worst case is a 300 ms delay before the preview catches
up.

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

- `src/pages/asset-library/components/BackgroundPreview.tsx` — the
  `PROP_REACTIVE_BACKGROUND_TYPES` set and the debounce-bridge fallback.
- `src/pages/asset-library/components/AssetPreviewBoundary.tsx` — local
  ErrorBoundary that contains preview crashes without reloading the tab.
- `src/lib/webgl/{WebGLContextManager,useWebGLLifecycle,usePropsRef}.ts` —
  shared lifecycle utilities (used by future migrations; existing components
  inline the propsRef pattern directly).
- `src/lib/asset-preview-error.ts` — telemetry helper hooked into
  `log-preview-error` Edge Function and `asset_preview_errors` table.
