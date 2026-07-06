import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import Shuffle from '../../vendor-components/Shuffle';
import type { TextContext } from '../../_runtime/types';
// WP-only typography reset (inherit host element's font/size/case from the
// runtime layer). Chunk-only import → SaaS preview keeps the showcase look.
import './shuffle.css';

(window as any).__bz_tx_register('shuffle', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (text: string, props: Record<string, unknown>) => {
    // textAlign: the vendor defaults to 'center' and applies it as an INLINE
    // style on .shuffle-parent, which beats the shuffle.css inherit reset —
    // on WP the effect must follow the host element's alignment instead.
    root.render(React.createElement(Shuffle, { text, textAlign: 'inherit', ...props }));
  };

  render(textCtx.text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(textCtx.text, newConfig),
    unmount: () => root.unmount(),
  };
});
