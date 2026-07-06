/**
 * Hubbee Element Runtime
 *
 * Hydrates element containers on WordPress pages.
 * Element chunks register via the runtime contract surface
 * (`window.__bz_el_runtime.register`); legacy chunks still hit the
 * pre-contract global `window.__bz_el_register` and continue to work.
 *
 * The runtime is intentionally slug-agnostic: it never imports a
 * per-effect mapper or wrapper. Anything slug-specific lives inside the
 * Storage-deployed chunk, so a Mapper iteration ships through the chunk
 * pipeline alone — no plugin redeploy needed (see
 * `feedback_runtime_contract_boundary.md`).
 */

import type { BzElRuntime, EffectInstance, MountFn } from './types';

/** Runtime contract version. Bump on any breaking change to the
 *  chunk ↔ runtime call shape so chunks can refuse to register. */
const CONTRACT_VERSION = 1;

// ── Debug ───────────────────────────────────────────────────────────
const DEBUG =
  typeof window !== 'undefined' &&
  ((window as unknown as { HUBBEE_DEBUG?: boolean }).HUBBEE_DEBUG ||
    document.documentElement.dataset.bzDebug === 'true');

function debugLog(ctx: string, msg: string, data?: unknown): void {
  if (!DEBUG) return;
  const ts = new Date().toISOString().substring(11, 23);
  console.log(`[HubbeeEl:${ctx}] ${ts} ${msg}`, data !== undefined ? data : '');
}

// ── Registry ────────────────────────────────────────────────────────
const registry = new Map<string, MountFn>();
const instances = new Map<HTMLElement, EffectInstance>();
const pendingQueue = new Map<HTMLElement, { type: string; config: Record<string, unknown> }>();

/**
 * Called by element chunks to register their mount function.
 * Flushes any pending containers that were waiting for this element.
 */
function registerEffect(name: string, mountFn: MountFn): void {
  debugLog('Registry', `Registered element "${name}"`);
  registry.set(name, mountFn);

  // Flush pending containers waiting for this element
  for (const [el, pending] of pendingQueue) {
    if (pending.type === name) {
      pendingQueue.delete(el);
      mountElement(el, mountFn, pending.type, pending.config);
    }
  }
}

// Publish the contract surface. New chunks resolve registration through
// `__bz_el_runtime.register` after a version check; legacy chunks still
// call the bare global, which we keep wired up for back-compat.
const runtimeContract: BzElRuntime = {
  version: CONTRACT_VERSION,
  register: registerEffect,
};
window.__bz_el_runtime = runtimeContract;
window.__bz_el_register = registerEffect;

// ── Mount / Teardown ────────────────────────────────────────────────

function mountElement(
  el: HTMLElement,
  mountFn: MountFn,
  type: string,
  config: Record<string, unknown>
): void {
  void type;
  // Create the element container
  const container = document.createElement('div');
  container.className = 'bz-el-container';

  // Append container inside the host element
  el.appendChild(container);

  // The runtime forwards the raw section config as-is. Each chunk owns
  // its slug-specific mapper (see effects/<slug>/index.tsx) so adding
  // or changing a mapper does NOT require a plugin redeploy.
  try {
    const instance = mountFn(container, config);
    instances.set(el, instance);

    debugLog('Mount', `Mounted element on`, { id: el.id, type });
  } catch (err) {
    console.error('[HubbeeEl] Failed to mount element:', err);
    container.remove();
  }
}

function hydrateContainer(el: HTMLElement): void {
  // Skip if already has an element container
  if (el.querySelector(':scope > .bz-el-container')) {
    return;
  }

  const type = el.dataset.bzEl;
  if (!type) return;

  let config: Record<string, unknown> = {};
  const configStr = el.dataset.bzElConfig;
  if (configStr) {
    try {
      config = JSON.parse(configStr);
    } catch (e) {
      console.warn('[HubbeeEl] Failed to parse config for', el, e);
      return;
    }
  }

  const mountFn = registry.get(type);

  if (mountFn) {
    mountElement(el, mountFn, type, config);
  } else {
    // Element chunk not loaded yet — queue for later
    debugLog('Queue', `Element "${type}" not yet registered, queuing`, { id: el.id });
    pendingQueue.set(el, { type, config });
  }
}

function teardown(el: HTMLElement): void {
  // Unmount the element instance
  const instance = instances.get(el);
  if (instance) {
    debugLog('Teardown', `Unmounting element`, { id: el.id });
    try {
      instance.unmount();
    } catch (err) {
      console.error('[HubbeeEl] Error during unmount:', err);
    }
    instances.delete(el);
  }

  // Remove the container div
  const container = el.querySelector(':scope > .bz-el-container');
  if (container) {
    container.remove();
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
      // Handle removed nodes — teardown elements
      for (const node of mutation.removedNodes) {
        if (node instanceof HTMLElement) {
          if (node.dataset.bzEl) {
            teardown(node);
          }
          const containers = node.querySelectorAll<HTMLElement>('[data-bz-el]');
          containers.forEach((el) => teardown(el));
        }
      }

      // Handle added nodes — observe new containers
      for (const node of mutation.addedNodes) {
        if (node instanceof HTMLElement) {
          if (node.dataset.bzEl) {
            observeElement(node);
          }
          const containers = node.querySelectorAll<HTMLElement>('[data-bz-el]');
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
  const containers = document.querySelectorAll<HTMLElement>('[data-bz-el]');
  debugLog('Init', `Found ${containers.length} element containers`);
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
window.HubbeeElRuntime = {
  hydrate,
  destroy,
  version: '1.0.0',
};
