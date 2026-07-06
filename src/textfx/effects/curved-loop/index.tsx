import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import CurvedLoop from '../../vendor-components/CurvedLoop';
import type { TextContext } from '../../_runtime/types';

(window as any).__bz_tx_register('curved-loop', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);
  const render = (text: string, props: Record<string, unknown>) => {
    root.render(React.createElement(CurvedLoop, { text, marqueeText: text, fontSize: textCtx.fontSize, ...props }));
  };
  render(textCtx.text, config);
  return {
    update: (newConfig: Record<string, unknown>) => render(textCtx.text, { ...newConfig, fontSize: (newConfig.fontSize as number) || textCtx.fontSize }),
    unmount: () => root.unmount(),
  };
});
