import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import ASCIIText from '../../vendor-components/ASCIIText';
import { normalizeFxText } from '../../_shared/normalize-text';
import type { TextContext } from '../../_runtime/types';

(window as any).__bz_tx_register('ascii-text', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);
  // The runtime sizes the layer to the ORIGINAL text line height (~1 line).
  // ASCII art needs a real canvas area to be legible — reserve a band via
  // min-height (the runtime's ResizeObserver re-sets `height`, never
  // min-height; same pattern as scroll-velocity).
  container.style.minHeight = `${Math.max(140, Math.round(textCtx.fontSize * 5))}px`;
  const text = normalizeFxText(textCtx.text);
  const render = (text: string, props: Record<string, unknown>) => {
    root.render(React.createElement(ASCIIText, { text, fontSize: textCtx.fontSize, ...props } as never));
  };
  render(text, config);
  return {
    update: (newConfig: Record<string, unknown>) => render(text, { ...newConfig, fontSize: (newConfig.fontSize as number) || textCtx.fontSize }),
    unmount: () => root.unmount(),
  };
});
