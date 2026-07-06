export interface EffectInstance {
  update(config: Record<string, unknown>): void;
  unmount(): void;
}

export type MountFn = (container: HTMLElement, config: Record<string, unknown>) => EffectInstance;

declare global {
  interface Window {
    __bz_bg_register?: (name: string, mountFn: MountFn) => void;
    HubbeeBgRuntime?: {
      hydrate(): void;
      destroy(): void;
      version: string;
    };
  }
}
