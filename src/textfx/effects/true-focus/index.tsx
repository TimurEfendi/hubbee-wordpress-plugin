import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import TrueFocus from './TrueFocusAdapted';
import { normalizeFxText } from '../../_shared/normalize-text';
import type { TextContext } from '../../_runtime/types';

(window as any).__bz_tx_register('true-focus', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);
  const text = normalizeFxText(textCtx.text);

  const render = (text: string, props: Record<string, unknown>) => {
    // The vendor's .focus-container is a flex row hardcoded to
    // justify-content:center — on WP it must follow the host element's
    // text-align (mirrored onto the layer by the runtime). Map it onto a
    // CSS var consumed by true-focus.css under .bz-tx-layer (WP-only).
    const ta = getComputedStyle(container).textAlign;
    const justify = ta === 'center' ? 'center' : (ta === 'right' || ta === 'end') ? 'flex-end' : 'flex-start';
    container.style.setProperty('--bz-tf-justify', justify);
    root.render(React.createElement(TrueFocus, { sentence: text, ...props }));
  };

  render(text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(text, newConfig),
    unmount: () => root.unmount(),
  };
});
