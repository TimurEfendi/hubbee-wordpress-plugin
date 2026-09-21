import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import CircularText from '../../vendor-components/CircularText';
import { normalizeFxText } from '../../_shared/normalize-text';
import type { TextContext } from '../../_runtime/types';

(window as any).__bz_tx_register('circular-text', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);
  // Diameter: originalHeight is ONE text line (~32px for an h2) → a 32px
  // circle with 12px glyphs, practically invisible. Derive the circle from
  // the element's font size instead (vendor demo default is 200px) and
  // reserve the band via min-height (the runtime's ResizeObserver re-sets
  // `height`, never min-height).
  const diameterFor = (fontSize: number) => Math.max(160, Math.round(fontSize * 6));
  const diameter = diameterFor(textCtx.fontSize);
  container.style.minHeight = `${diameter}px`;
  const text = normalizeFxText(textCtx.text);
  const render = (text: string, props: Record<string, unknown>) => {
    root.render(React.createElement(CircularText, { text, diameter, ...props }));
  };
  render(text, config);
  return {
    update: (newConfig: Record<string, unknown>) => {
      const d = diameterFor((newConfig.fontSize as number) || textCtx.fontSize);
      container.style.minHeight = `${d}px`;
      render(text, { ...newConfig, diameter: d });
    },
    unmount: () => root.unmount(),
  };
});
