import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import SplitText from '../../vendor-components/SplitText';
import type { TextContext } from '../../_runtime/types';

(window as any).__bz_tx_register('split-text', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (text: string, props: Record<string, unknown>) => {
    // textAlign: the vendor defaults to 'center' as an INLINE style on its
    // tag — on WP the effect must follow the host element's alignment.
    root.render(React.createElement(SplitText, { text, textAlign: 'inherit', ...props }));
  };

  render(textCtx.text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(textCtx.text, newConfig),
    unmount: () => root.unmount(),
  };
});
