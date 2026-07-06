/**
 * Hubbee Asset Loader — the single always-present frontend bootstrap.
 *
 * WHY: chunk loading used to be decided by Elementor's per-element
 * `before_render` PHP hooks. When Elementor serves an element from its
 * element cache, that hook does not fire, so the chunk was silently dropped
 * (effects/backgrounds intermittently failed to load). This loader instead
 * drives loading from the RENDERED DOM (`data-bz-*` attributes, which are
 * baked into the cached HTML), and loads each dependency chain ON VIEWPORT
 * INTERSECTION — so only the chunks for assets actually on screen load, and
 * the heavy background vendor chain (Three.js / R3F) is deferred until a
 * background is in view.
 *
 * The per-system runtimes (textfx/element/background) still do the mounting
 * via their existing IntersectionObserver + pendingQueue; this loader only
 * ensures their dependency chain (vendor → runtime → chunk) is present, in
 * order. Runtimes/chunks self-register on load and the runtime flushes its
 * queue — so the loader never touches mounting.
 */

interface BzAssets {
  /** Public URL of wp-content/uploads/hubbee/chunks (no trailing slash). */
  chunkBase: string;
  /** Public URL of the plugin's assets/js dir (no trailing slash). */
  pluginBase: string;
  /** Cache-bust token for plugin-bundled assets (runtimes + vendors). */
  pluginVer: string;
  /** Per-type cache-bust token for downloaded chunks (slug → sha16). */
  chunkVers: Record<string, string>;
}

const A = (window as unknown as { __bzAssets?: BzAssets }).__bzAssets;

// ── Dedup-aware sequential loaders ──────────────────────────────────
const inflight = new Map<string, Promise<void>>();

function loadScript(url: string): Promise<void> {
  const existing = inflight.get(url);
  if (existing) return existing;
  const p = new Promise<void>((resolve, reject) => {
    const s = document.createElement('script');
    s.src = url;
    s.async = false; // dynamically-injected; order is enforced by awaiting below
    s.onload = () => resolve();
    s.onerror = () => reject(new Error(`Failed to load ${url}`));
    document.head.appendChild(s);
  });
  inflight.set(url, p);
  return p;
}

function loadStyle(url: string): void {
  if (inflight.has(url)) return;
  inflight.set(url, Promise.resolve());
  const l = document.createElement('link');
  l.rel = 'stylesheet';
  l.href = url;
  document.head.appendChild(l);
}

/** Load a dependency chain strictly in order (each awaits the previous). */
async function loadChain(urls: string[]): Promise<void> {
  for (const url of urls) {
    await loadScript(url);
  }
}

// ── URL builders ────────────────────────────────────────────────────
function pluginUrl(rel: string): string {
  return `${A!.pluginBase}/${rel}?v=${encodeURIComponent(A!.pluginVer)}`;
}
function chunkUrl(prefix: string, type: string): string {
  const stem = `${prefix}-${type}`;
  const v = A!.chunkVers[stem];
  return `${A!.chunkBase}/${stem}.min.js${v ? `?v=${encodeURIComponent(v)}` : ''}`;
}

const VENDOR_REACT = () => pluginUrl('backgrounds/vendor-react.min.js');

function loadFor(el: HTMLElement): void {
  const tx = el.dataset.bzTextfx;
  const elType = el.dataset.bzEl;
  const bg = el.dataset.bzBg;

  if (tx) {
    void loadChain([VENDOR_REACT(), pluginUrl('textfx/textfx-runtime.min.js'), chunkUrl('tx', tx)]);
  } else if (elType) {
    loadStyle(pluginUrl('elements/style.css'));
    void loadChain([VENDOR_REACT(), pluginUrl('elements/element-runtime.min.js'), chunkUrl('el', elType)]);
  } else if (bg) {
    // Heavy chain (Three.js + R3F) — only ever loaded when a background
    // actually reaches the viewport.
    void loadChain([
      VENDOR_REACT(),
      pluginUrl('backgrounds/vendor-three.min.js'),
      pluginUrl('backgrounds/vendor-r3f.min.js'),
      pluginUrl('backgrounds/runtime.min.js'),
      chunkUrl('bg', bg),
    ]);
  }
}

// ── Observe + lazy-trigger ──────────────────────────────────────────
const SELECTOR = '[data-bz-textfx],[data-bz-el],[data-bz-bg]';

function init(): void {
  if (!A || !A.chunkBase || !A.pluginBase) return; // globals not injected — nothing to do

  const io = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (entry.isIntersecting) {
          const el = entry.target as HTMLElement;
          io.unobserve(el);
          loadFor(el);
        }
      }
    },
    { rootMargin: '200px' }
  );

  const observeAll = (root: ParentNode): void => {
    root.querySelectorAll<HTMLElement>(SELECTOR).forEach((el) => io.observe(el));
  };

  observeAll(document);

  // Late-added containers (Elementor editor AJAX / dynamic content).
  const mo = new MutationObserver((mutations) => {
    for (const m of mutations) {
      for (const node of m.addedNodes) {
        if (node.nodeType !== 1) continue;
        const el = node as HTMLElement;
        if (el.matches?.(SELECTOR)) io.observe(el);
        if (el.querySelectorAll) observeAll(el);
      }
    }
  });
  mo.observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
