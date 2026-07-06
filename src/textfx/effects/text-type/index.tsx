import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import TextType from '../../vendor-components/TextType';
import type { TextContext } from '../../_runtime/types';

(window as any).__bz_tx_register('text-type', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (text: string, props: Record<string, unknown>) => {
    root.render(React.createElement(TextType, { text, ...props }));
  };

  render(textCtx.text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(textCtx.text, newConfig),
    unmount: () => root.unmount(),
  };
});
