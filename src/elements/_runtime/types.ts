export interface EffectInstance {
  update(config: Record<string, unknown>): void;
  unmount(): void;
}

export type MountFn = (container: HTMLElement, config: Record<string, unknown>) => EffectInstance;

/**
 * Stable contract surface exposed by the plugin-bundled runtime to
 * Storage-deployed element chunks. The `version` is bumped on any
 * breaking change to the chunk ↔ runtime contract (registration shape,
 * mount call signature, etc.) so chunks can refuse to register against
 * an incompatible runtime instead of silently mis-rendering.
 *
 * Chunks should resolve `register` via the helper at
 * `WP_hubbee/src/elements/_chunk/register.ts`, which falls back to the
 * legacy global for runtimes that predate the contract.
 */
export interface BzElRuntime {
  version: number;
  register: (name: string, mountFn: MountFn) => void;
}

declare global {
  interface Window {
    __bz_el_register?: (name: string, mountFn: MountFn) => void;
    __bz_el_runtime?: BzElRuntime;
    HubbeeElRuntime?: {
      hydrate(): void;
      destroy(): void;
      version: string;
    };
  }
}
