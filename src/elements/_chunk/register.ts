/**
 * Chunk-side registration helper.
 *
 * Element chunks must NOT depend on slug-specific code in the plugin-
 * bundled runtime — that's how we end up with the "deploy two pipelines
 * in lockstep" drift class (see feedback_runtime_contract_boundary.md).
 *
 * Instead, chunks call `registerElementChunk(slug, mountFn)` from this
 * tiny helper. It picks the right global:
 *   - New runtime exposes `window.__bz_el_runtime = { version, register }`.
 *     Chunks check that the runtime is at least the version they need
 *     and register through it.
 *   - Old runtime (predating the contract) only exposes
 *     `window.__bz_el_register`. The helper falls back to that and warns
 *     so the deploy-drift surfaces in the browser console (and via
 *     drift telemetry once that exists).
 *
 * Bump `requiredVersion` per chunk if you add a feature that needs a
 * newer runtime contract.
 */

import type { MountFn } from '../_runtime/types';
import { ensureConfigFonts } from './fonts';

/**
 * Wrap a mount function so configured webfonts are loaded BEFORE the first
 * render (and before config updates re-render). Canvas/WebGL renderers
 * rasterize text at mount — without this, Google-font typography configured
 * in the SaaS silently degraded to fallback fonts on the published page,
 * while system fonts resolve immediately (no added latency).
 */
function withFontPreload(mountFn: MountFn): MountFn {
  return (container, config) => {
    let handle: ReturnType<MountFn> | null = null;
    let unmounted = false;
    let pendingConfig: Record<string, unknown> | null = null;
    void ensureConfigFonts(config).then(() => {
      if (unmounted) return;
      handle = mountFn(container, config);
      if (pendingConfig) {
        handle.update(pendingConfig);
        pendingConfig = null;
      }
    });
    return {
      update: (cfg: Record<string, unknown>) => {
        void ensureConfigFonts(cfg).then(() => {
          if (unmounted) return;
          if (handle) handle.update(cfg);
          else pendingConfig = cfg;
        });
      },
      unmount: () => {
        unmounted = true;
        handle?.unmount();
        handle = null;
      },
    };
  };
}

export function registerElementChunk(
  slug: string,
  rawMountFn: MountFn,
  requiredVersion = 1
): void {
  const mountFn = withFontPreload(rawMountFn);
  const w = window as unknown as {
    __bz_el_runtime?: { version: number; register: (n: string, fn: MountFn) => void };
    __bz_el_register?: (n: string, fn: MountFn) => void;
  };

  const runtime = w.__bz_el_runtime;
  if (runtime && typeof runtime.register === 'function' && runtime.version >= requiredVersion) {
    runtime.register(slug, mountFn);
    return;
  }

  const legacy = w.__bz_el_register;
  if (typeof legacy === 'function') {
    legacy(slug, mountFn);
    if (typeof console !== 'undefined') {
      console.warn(
        `[Hubbee] Element "${slug}" registered via legacy global. ` +
          `Plugin runtime contract is older than v${requiredVersion} ` +
          `(have v${runtime?.version ?? 'unknown'}). ` +
          `Update the WP plugin to the latest build.`
      );
    }
    return;
  }

  if (typeof console !== 'undefined') {
    console.error(
      `[Hubbee] Element "${slug}" cannot register: no element runtime found on this page.`
    );
  }
}
