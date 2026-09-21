import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import TextType from '../../vendor-components/TextType';
import { normalizeFxText } from '../../_shared/normalize-text';
import type { TextContext } from '../../_runtime/types';

(window as any).__bz_tx_register('text-type', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);
  const text = normalizeFxText(textCtx.text);

  const render = (text: string, props: Record<string, unknown>) => {
    root.render(React.createElement(TextType, { text, ...props }));
  };

  render(text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(text, newConfig),
    unmount: () => root.unmount(),
  };
});
