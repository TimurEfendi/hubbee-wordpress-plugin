export interface EffectInstance {
  update(config: Record<string, unknown>): void;
  unmount(): void;
}

export interface TextContext {
  /**
   * RAW `textContent` of the target element — includes Elementor HTML source
   * formatting (newlines/tab indentation around the text node). Chunks must
   * normalize via `_shared/normalize-text` before passing it to whitespace-
   * sensitive vendor components; rotating-text relies on the raw newlines
   * for its `splitBy: 'lines'` mode, so the runtime never normalizes here.
   */
  text: string;
  /** Raw innerHTML for effects that need rich formatting */
  html: string;
  /** Computed font-size of the original text element in px */
  fontSize: number;
  /** Original text element width in px */
  originalWidth: number;
  /** Original text element height in px */
  originalHeight: number;
}

export type TextMountFn = (
  container: HTMLElement,
  textCtx: TextContext,
  config: Record<string, unknown>
) => EffectInstance;

declare global {
  interface Window {
    __bz_tx_register?: (name: string, mountFn: TextMountFn) => void;
    HubbeeTxRuntime?: {
      hydrate(): void;
      destroy(): void;
      version: string;
    };
  }
}
