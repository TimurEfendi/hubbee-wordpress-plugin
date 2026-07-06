/**
 * Hubbee Background Runtime
 *
 * Hydrates background effect containers on WordPress pages.
 * Effect chunks register via window.__bz_bg_register(name, mountFn).
 * The runtime lazily mounts effects using IntersectionObserver and
 * watches for DOM mutations (Elementor editor AJAX rerenders).
 */

import type { EffectInstance, MountFn } from './types';

// ── Debug ───────────────────────────────────────────────────────────
const DEBUG =
  typeof window !== 'undefined' &&
  ((window as unknown as { HUBBEE_DEBUG?: boolean }).HUBBEE_DEBUG ||
    document.documentElement.dataset.bzDebug === 'true');

function debugLog(ctx: string, msg: string, data?: unknown): void {
  if (!DEBUG) return;
  const ts = new Date().toISOString().substring(11, 23);
  console.log(`[HubbeeBg:${ctx}] ${ts} ${msg}`, data !== undefined ? data : '');
}

// ── Registry ────────────────────────────────────────────────────────
const registry = new Map<string, MountFn>();
const instances = new Map<HTMLElement, EffectInstance>();
const pendingQueue = new Map<HTMLElement, { type: string; config: Record<string, unknown>; opacity: string }>();
const mouseForwarders = new Map<HTMLElement, { dispatchToAll: (e: Event) => void; mouseEvents: readonly string[]; touchEvents: readonly string[] }>();

/**
 * Called by effect chunks to register their mount function.
 * Flushes any pending containers that were waiting for this effect.
 */
function registerEffect(name: string, mountFn: MountFn): void {
  debugLog('Registry', `Registered effect "${name}"`);
  registry.set(name, mountFn);

  // Flush pending containers waiting for this effect
  for (const [el, pending] of pendingQueue) {
    if (pending.type === name) {
      pendingQueue.delete(el);
      mountEffect(el, mountFn, pending.config, pending.opacity);
    }
  }
}

// Expose registration function globally
window.__bz_bg_register = registerEffect;

// ── Mount / Teardown ────────────────────────────────────────────────

function mountEffect(
  el: HTMLElement,
  mountFn: MountFn,
  config: Record<string, unknown>,
  opacity: string
): void {
  // Ensure container is positioned for absolute child
  const computed = getComputedStyle(el);
  if (computed.position === 'static') {
    el.style.position = 'relative';
  }

  // Create the background layer with inline styles (CSS-independent)
  const layer = document.createElement('div');
  layer.className = 'bz-bg-layer';
  layer.style.cssText = `position:absolute;inset:0;z-index:0;pointer-events:none;overflow:hidden;${opacity !== '1' ? `opacity:${opacity};` : ''}`;

  // Insert as first child so content remains on top
  el.insertBefore(layer, el.firstChild);

  // Ensure content wrapper stays above the background
  const inner = el.querySelector(':scope > .e-con-inner') as HTMLElement | null;
  if (inner) {
    inner.style.position = 'relative';
    inner.style.zIndex = '1';
  }

  try {
    const instance = mountFn(layer, config);
    instances.set(el, instance);

    // Forward ALL mouse/pointer/touch events from container to background layer + children.
    // The layer has pointer-events:none so it never receives events directly.
    // We forward from the container (which HAS pointer-events) to the layer tree.
    // CRITICAL: bubbles=false prevents infinite loop.
    let forwarding = false;

    const dispatchToAll = (event: Event) => {
      if (forwarding) return;
      forwarding = true;

      const targets = [layer, ...Array.from(layer.querySelectorAll('*'))] as HTMLElement[];

      if (event instanceof MouseEvent) {
        const { clientX, clientY, button, buttons } = event;
        const baseOpts = { clientX, clientY, button, buttons, bubbles: false };

        for (const t of targets) {
          t.dispatchEvent(new MouseEvent(event.type, baseOpts));
          // Also dispatch pointer equivalent
          const pointerType = event.type.replace('mouse', 'pointer')
            .replace('click', 'pointerdown'); // click has no pointer equiv
          if (pointerType !== event.type && pointerType !== 'pointerclick') {
            t.dispatchEvent(new PointerEvent(pointerType, { ...baseOpts, pointerId: 1, pointerType: 'mouse' as const }));
          }
        }
      } else if (event instanceof TouchEvent && event.touches.length > 0) {
        const touch = event.touches[0] || event.changedTouches[0];
        if (touch) {
          const opts = { clientX: touch.clientX, clientY: touch.clientY, bubbles: false };
          for (const t of targets) {
            t.dispatchEvent(new MouseEvent('mousemove', opts));
            t.dispatchEvent(new PointerEvent('pointermove', { ...opts, pointerId: 1, pointerType: 'touch' as const }));
          }
        }
      }

      forwarding = false;
    };

    // Mouse events
    const mouseEvents = ['mousemove', 'mouseenter', 'mouseleave', 'mousedown', 'mouseup', 'click'] as const;
    for (const evt of mouseEvents) {
      el.addEventListener(evt, dispatchToAll as EventListener);
    }

    // Touch events
    const touchEvents = ['touchstart', 'touchmove', 'touchend'] as const;
    for (const evt of touchEvents) {
      el.addEventListener(evt, dispatchToAll as EventListener, { passive: true });
    }

    mouseForwarders.set(el, { dispatchToAll, mouseEvents, touchEvents });

    debugLog('Mount', `Mounted effect on`, { id: el.id, type: config.type });
  } catch (err) {
    console.error('[HubbeeBg] Failed to mount effect:', err);
    layer.remove();
  }
}

function hydrateContainer(el: HTMLElement): void {
  // Skip if already has a background layer
  if (el.querySelector(':scope > .bz-bg-layer')) {
    return;
  }

  const type = el.dataset.bzBg;
  if (!type) return;

  let config: Record<string, unknown> = {};
  const configStr = el.dataset.bzBgConfig;
  if (configStr) {
    try {
      config = JSON.parse(configStr);
    } catch (e) {
      console.warn('[HubbeeBg] Failed to parse config for', el, e);
      return;
    }
  }

  const opacity = el.dataset.bzBgOpacity || '1';
  const mountFn = registry.get(type);

  if (mountFn) {
    mountEffect(el, mountFn, config, opacity);
  } else {
    // Effect chunk not loaded yet — queue for later
    debugLog('Queue', `Effect "${type}" not yet registered, queuing`, { id: el.id });
    pendingQueue.set(el, { type, config, opacity });
  }
}

function teardown(el: HTMLElement): void {
  // Remove all event forwarders
  const fwd = mouseForwarders.get(el);
  if (fwd) {
    for (const evt of fwd.mouseEvents) {
      el.removeEventListener(evt, fwd.dispatchToAll as EventListener);
    }
    for (const evt of fwd.touchEvents) {
      el.removeEventListener(evt, fwd.dispatchToAll as EventListener);
    }
    mouseForwarders.delete(el);
  }

  // Unmount the effect instance
  const instance = instances.get(el);
  if (instance) {
    debugLog('Teardown', `Unmounting effect`, { id: el.id });
    try {
      instance.unmount();
    } catch (err) {
      console.error('[HubbeeBg] Error during unmount:', err);
    }
    instances.delete(el);
  }

  // Remove the layer div
  const layer = el.querySelector(':scope > .bz-bg-layer');
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
          if (node.dataset.bzBg) {
            teardown(node);
          }
          const containers = node.querySelectorAll<HTMLElement>('[data-bz-bg]');
          containers.forEach((el) => teardown(el));
        }
      }

      // Handle added nodes — observe new containers
      for (const node of mutation.addedNodes) {
        if (node instanceof HTMLElement) {
          if (node.dataset.bzBg) {
            observeElement(node);
          }
          const containers = node.querySelectorAll<HTMLElement>('[data-bz-bg]');
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

/**
 * Verify the shared THREE / r3f globals are present before hydrating.
 * Each `bg-*.min.js` effect chunk is built with `three` + `@react-three/*`
 * marked as externals (mapped to window.THREE / window.HubbeeR3F). If those
 * vendor chunks failed to load — e.g. plugin-ZIP build dropped them, CSP
 * blocked them, the script tag is missing — the effects would crash with
 * an opaque "Cannot read properties of undefined" instead of a clear hint.
 * Surface a single console error and skip mounting so the rest of the page
 * keeps working.
 */
function vendorChunksReady(): boolean {
  const w = window as unknown as { THREE?: unknown; HubbeeR3F?: { fiber?: unknown } };
  if (!w.THREE) {
    console.error(
      '[HubbeeBg] vendor-three.min.js missing — cannot hydrate background effects. ' +
        'Check that the plugin ZIP shipped vendor-three.min.js and the script is enqueued.',
    );
    return false;
  }
  if (!w.HubbeeR3F?.fiber) {
    // r3f is only used by 3 effects (silk/dither/beams) — warn but allow mount;
    // non-r3f effects will still work, r3f effects will crash visibly with their
    // own error path.
    console.warn(
      '[HubbeeBg] vendor-r3f.min.js missing — r3f-based effects (silk/dither/beams) will fail to mount.',
    );
  }
  return true;
}

function hydrate(): void {
  if (!vendorChunksReady()) return;
  const containers = document.querySelectorAll<HTMLElement>('[data-bz-bg]');
  debugLog('Init', `Found ${containers.length} background containers`);
  containers.forEach((el) => observeElement(el));
  startMutationObserver();
}

function destroy(): void {
  // Teardown all active instances
  for (const [el] of instances) {
    teardown(el);
  }

  // Clear pending queue
  pendingQueue.clear();

  // Disconnect observers
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
window.HubbeeBgRuntime = {
  hydrate,
  destroy,
  version: '1.0.0',
};
