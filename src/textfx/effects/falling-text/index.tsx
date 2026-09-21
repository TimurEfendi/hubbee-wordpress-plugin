import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import FallingText from '../../vendor-components/FallingText';
import { normalizeFxText } from '../../_shared/normalize-text';
import type { TextContext } from '../../_runtime/types';
// WP-only typography reset (chunk-only → preview keeps showcase).
import './falling-text.css';

(window as any).__bz_tx_register('falling-text', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);
  const text = normalizeFxText(textCtx.text);

  const render = (text: string, props: Record<string, unknown>, fontSize: number) => {
    // fontSize AFTER the spread: the words always inherit the host element's
    // size (erben-Prinzip) — also overrides legacy configs that still carry
    // a fontSize value from the removed control.
    root.render(React.createElement(FallingText, { text, ...props, fontSize: `${fontSize}px` }));
  };

  render(text, config, textCtx.fontSize);

  return {
    update: (newConfig: Record<string, unknown>) =>
      // The runtime's ResizeObserver passes the re-measured element font size
      // as a NUMBER; legacy string values from the removed control are ignored.
      render(text, newConfig, typeof newConfig.fontSize === 'number' ? newConfig.fontSize : textCtx.fontSize),
    unmount: () => root.unmount(),
  };
});
