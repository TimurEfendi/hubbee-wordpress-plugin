import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import TextPressure from '../../vendor-components/TextPressure';
import type { TextContext } from '../../_runtime/types';

(window as any).__bz_tx_register('text-pressure', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (text: string, props: Record<string, unknown>) => {
    root.render(React.createElement(TextPressure, { text, minFontSize: textCtx.fontSize, ...props }));
  };

  render(textCtx.text, config);

  return {
    update: (newConfig: Record<string, unknown>) => {
      const fs = (newConfig.fontSize as number) || textCtx.fontSize;
      render(textCtx.text, { ...newConfig, minFontSize: fs });
    },
    unmount: () => root.unmount(),
  };
});
