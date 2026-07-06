import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import DecryptedText from '../../vendor-components/DecryptedText';
import type { TextContext } from '../../_runtime/types';

(window as any).__bz_tx_register('decrypted-text', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);
  const render = (text: string, props: Record<string, unknown>) => {
    root.render(React.createElement(DecryptedText, { text, fontSize: textCtx.fontSize, ...props }));
  };
  render(textCtx.text, config);
  return {
    update: (newConfig: Record<string, unknown>) => render(textCtx.text, { ...newConfig, fontSize: (newConfig.fontSize as number) || textCtx.fontSize }),
    unmount: () => root.unmount(),
  };
});
