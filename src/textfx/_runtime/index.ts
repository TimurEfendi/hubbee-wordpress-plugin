/**
 * Hubbee Text Effect Runtime
 *
 * Hydrates text effect containers on WordPress pages.
 * Effect chunks register via window.__bz_tx_register(name, mountFn).
 * The runtime lazily mounts effects using IntersectionObserver and
 * watches for DOM mutations (Elementor editor AJAX rerenders).
 *
 * Unlike backgrounds (overlay behind content) or elements (own container),
 * text effects enhance existing text: they read the original text element,
 * hide it, and render an enhanced React version alongside it.
 */

import type { EffectInstance, TextMountFn, TextContext } from './types';

// ── Debug ───────────────────────────────────────────────────────────
const DEBUG =
  typeof window !== 'undefined' &&
  ((window as unknown as { HUBBEE_DEBUG?: boolean }).HUBBEE_DEBUG ||
    document.documentElement.dataset.bzDebug === 'true');

function debugLog(ctx: string, msg: string, data?: unknown): void {
  if (!DEBUG) return;
  const ts = new Date().toISOString().substring(11, 23);
  console.log(`[HubbeeTx:${ctx}] ${ts} ${msg}`, data !== undefined ? data : '');
}

// ── Text element selectors (priority order) ─────────────────────────
const TEXT_SELECTORS = [
  '.elementor-heading-title',
  'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
  'p',
  '.elementor-text-editor',
] as const;

function findTextTarget(container: HTMLElement): HTMLElement | null {
  // Check for explicit target selector first
  const targetSelector = container.dataset.bzTextfxTarget;
  if (targetSelector) {
    return container.querySelector<HTMLElement>(targetSelector);
  }

  // Auto-discover first text element in priority order
  for (const sel of TEXT_SELECTORS) {
    const el = container.querySelector<HTMLElement>(sel);
    if (el) return el;
  }

  // Fallback: some widgets carry their text DIRECTLY on the data-bz-textfx
  // wrapper with no standard child element — e.g. a Text-Editor whose content
  // is a bare text node. Without a target the effect would never mount. Wrap
  // the wrapper's own content in a <span> so the rest of the mount flow works
  // unchanged: the span is a descendant, so the layer still lands INSIDE the
  // container (sibling of the span), and the span inherits the wrapper's
  // computed typography (size/family/weight) — which applyTypography then
  // mirrors onto the layer. The original markup is kept in the DOM (just
  // moved into the span) for SEO + textCtx.html.
  //
  // Idempotent: on a teardown→re-hydrate cycle the span already exists, so we
  // reuse it instead of nesting another wrapper.
  const existing = container.querySelector<HTMLElement>(':scope > .bz-tx-original');
  if (existing) return existing;

  if ((container.textContent || '').trim()) {
    const span = document.createElement('span');
    span.className = 'bz-tx-original';
    while (container.firstChild) {
      span.appendChild(container.firstChild);
    }
    container.appendChild(span);
    return span;
  }

  return null;
}

// ── Registry ────────────────────────────────────────────────────────
const registry = new Map<string, TextMountFn>();
const instances = new Map<HTMLElement, EffectInstance>();
const hiddenOriginals = new Map<HTMLElement, { target: HTMLElement; originalDisplay: string }>();
const pendingQueue = new Map<HTMLElement, { type: string; config: Record<string, unknown> }>();

/**
 * Called by effect chunks to register their mount function.
 * Flushes any pending containers that were waiting for this effect.
 */
function registerEffect(name: string, mountFn: TextMountFn): void {
  debugLog('Registry', `Registered text effect "${name}"`);
  registry.set(name, mountFn);

  // Flush pending containers waiting for this effect
  for (const [el, pending] of pendingQueue) {
    if (pending.type === name) {
      pendingQueue.delete(el);
      mountEffect(el, mountFn, pending.config);
    }
  }
}

// Expose registration function globally
window.__bz_tx_register = registerEffect;

// ── Typography mirroring ────────────────────────────────────────────
// Effect chunks render their vendor component into `.bz-tx-layer` and
// (mostly) do not set their own font — so the rendered text inherits from
// the layer. The layer therefore must carry the ORIGINAL text element's
// computed typography, otherwise the effect falls back to the page/body
// default (small, system font) instead of matching the heading. This is a
// generic runtime concern (applies to every effect), so it lives here in
// the runtime rather than in any slug-specific chunk.
const TYPOGRAPHY_PROPS = [
  'fontFamily',
  'fontSize',
  'fontWeight',
  'fontStyle',
  'lineHeight',
  'letterSpacing',
  'textAlign',
  'textTransform',
  'color',
  'fontVariant',
  'wordSpacing',
] as const;

function applyTypography(layer: HTMLElement, cs: CSSStyleDeclaration): void {
  const style = layer.style as unknown as Record<string, string>;
  for (const prop of TYPOGRAPHY_PROPS) {
    const value = cs[prop as keyof CSSStyleDeclaration] as string | undefined;
    if (value) {
      style[prop] = value;
    }
  }
}

// ── Mount / Teardown ────────────────────────────────────────────────

function mountEffect(
  el: HTMLElement,
  mountFn: TextMountFn,
  config: Record<string, unknown>
): void {
  // Find the text element to enhance
  const target = findTextTarget(el);
  if (!target) {
    debugLog('Mount', 'No text target found in container', { id: el.id });
    return;
  }

  // Measure text metrics BEFORE hiding the original
  const computedStyle = getComputedStyle(target);
  const fontSize = parseFloat(computedStyle.fontSize) || 16;
  const originalHeight = target.offsetHeight || fontSize;
  const originalWidth = target.offsetWidth || 0;

  // Extract text content before hiding
  const textCtx: TextContext = {
    text: target.textContent || '',
    html: target.innerHTML,
    fontSize,
    originalWidth,
    originalHeight,
  };

  if (!textCtx.text.trim()) {
    debugLog('Mount', 'Target text is empty, skipping', { id: el.id });
    return;
  }

  // Create the text effect layer as a sibling after the target
  // Height matches original text element exactly 1:1
  const layerHeight = originalHeight;

  const layer = document.createElement('div');
  layer.className = 'bz-tx-layer';
  layer.style.cssText = `width:100%;height:${layerHeight}px;position:relative;overflow:visible;`;
  // Mirror the original element's typography (size + family + weight + …)
  // so the vendor component inherits the exact look. Read while the target
  // is still visible (before it is hidden below).
  applyTypography(layer, computedStyle);

  // Insert layer after the target element
  target.parentNode!.insertBefore(layer, target.nextSibling);

  // Hide original (keep in DOM for SEO)
  const originalDisplay = target.style.display;
  target.style.display = 'none';
  hiddenOriginals.set(el, { target, originalDisplay });

  try {
    const instance = mountFn(layer, textCtx, config);
    instances.set(el, instance);
    debugLog('Mount', `Mounted text effect on`, { id: el.id, text: textCtx.text.substring(0, 30), fontSize });

    // ResizeObserver: adapt layer when widget container resizes
    if (typeof ResizeObserver !== 'undefined') {
      const ro = new ResizeObserver(() => {
        // Re-measure typography (could change via Elementor responsive
        // breakpoints) while the target is briefly visible, then re-apply
        // it to the layer so the effect keeps matching the original.
        target.style.display = originalDisplay || '';
        const newComputed = getComputedStyle(target);
        const newFontSize = parseFloat(newComputed.fontSize) || fontSize;
        const newOriginalHeight = target.offsetHeight || 0;
        const newOriginalWidth = target.offsetWidth || 0;
        applyTypography(layer, newComputed);
        target.style.display = 'none';

        layer.style.height = `${newOriginalHeight || newFontSize}px`;

        instance.update({ ...config, fontSize: newFontSize, originalHeight: newOriginalHeight, originalWidth: newOriginalWidth });
      });
      ro.observe(el);

      // Store observer reference for cleanup
      const originalUnmount = instance.unmount;
      instance.unmount = () => {
        ro.disconnect();
        originalUnmount();
      };
    }
  } catch (err) {
    console.error('[HubbeeTx] Failed to mount text effect:', err);
    // Restore original on failure
    layer.remove();
    target.style.display = originalDisplay;
    hiddenOriginals.delete(el);
  }
}

function hydrateContainer(el: HTMLElement): void {
  // Skip if already has a text effect layer
  if (el.querySelector(':scope .bz-tx-layer')) {
    return;
  }

  const type = el.dataset.bzTextfx;
  if (!type) return;

  let config: Record<string, unknown> = {};
  const configStr = el.dataset.bzTextfxConfig;
  if (configStr) {
    try {
      config = JSON.parse(configStr);
    } catch (e) {
      console.warn('[HubbeeTx] Failed to parse config for', el, e);
      return;
    }
  }

  const mountFn = registry.get(type);

  if (mountFn) {
    mountEffect(el, mountFn, config);
  } else {
    debugLog('Queue', `Text effect "${type}" not yet registered, queuing`, { id: el.id });
    pendingQueue.set(el, { type, config });
  }
}

function teardown(el: HTMLElement): void {
  // Unmount the effect instance
  const instance = instances.get(el);
  if (instance) {
    debugLog('Teardown', `Unmounting text effect`, { id: el.id });
    try {
      instance.unmount();
    } catch (err) {
      console.error('[HubbeeTx] Error during unmount:', err);
    }
    instances.delete(el);
  }

  // Restore original text element visibility
  const hidden = hiddenOriginals.get(el);
  if (hidden) {
    hidden.target.style.display = hidden.originalDisplay;
    hiddenOriginals.delete(el);
  }

  // Remove the layer div
  const layer = el.querySelector('.bz-tx-layer');
  if (layer) {
    layer.remove();
  }

  // Clean up pending queue
  pendingQueue.delete(el);
}

// ── IntersectionObserver (lazy mount) ───────────────────────────────
let intersectionObserver: IntersectionObserver | null = null;

function getObserver(): IntersectionObserver {
  if (!intersectionObserver) {
    intersectionObserver = new IntersectionObserver(
      (entries) => {
        for (const entry of entries) {
          if (entry.isIntersecting) {
            const el = entry.target as HTMLElement;
            intersectionObserver!.unobserve(el);
            hydrateContainer(el);
          }
        }
      },
      { rootMargin: '200px' }
    );
  }
  return intersectionObserver;
}

function observeElement(el: HTMLElement): void {
  getObserver().observe(el);
}

// ── MutationObserver (dynamic content) ──────────────────────────────
let mutationObserver: MutationObserver | null = null;

function startMutationObserver(): void {
  if (mutationObserver) return;

  mutationObserver = new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      // Handle removed nodes — teardown effects
      for (const node of mutation.removedNodes) {
        if (node instanceof HTMLElement) {
          if (node.dataset.bzTextfx) {
            teardown(node);
          }
          const containers = node.querySelectorAll<HTMLElement>('[data-bz-textfx]');
          containers.forEach((el) => teardown(el));
        }
      }

      // Handle added nodes — observe new containers
      for (const node of mutation.addedNodes) {
        if (node instanceof HTMLElement) {
          if (node.dataset.bzTextfx) {
            observeElement(node);
          }
          const containers = node.querySelectorAll<HTMLElement>('[data-bz-textfx]');
          containers.forEach((el) => observeElement(el));
        }
      }
    }
  });

  mutationObserver.observe(document.body, {
    childList: true,
    subtree: true,
  });

  debugLog('Init', 'MutationObserver started');
}

// ── Public API ──────────────────────────────────────────────────────

function hydrate(): void {
  const containers = document.querySelectorAll<HTMLElement>('[data-bz-textfx]');
  debugLog('Init', `Found ${containers.length} text effect containers`);
  containers.forEach((el) => hydrateContainer(el));
  startMutationObserver();
}

function destroy(): void {
  for (const [el] of instances) {
    teardown(el);
  }

  pendingQueue.clear();

  if (intersectionObserver) {
    intersectionObserver.disconnect();
    intersectionObserver = null;
  }
  if (mutationObserver) {
    mutationObserver.disconnect();
    mutationObserver = null;
  }

  debugLog('Destroy', 'Runtime destroyed');
}

// ── Init on DOMContentLoaded ────────────────────────────────────────
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => hydrate());
} else {
  hydrate();
}

// ── Global API ──────────────────────────────────────────────────────
window.HubbeeTxRuntime = {
  hydrate,
  destroy,
  version: '1.0.0',
};
