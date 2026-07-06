import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import ScrambledText from '../../vendor-components/ScrambledText';
import type { TextContext } from '../../_runtime/types';
// WP-only typography reset (chunk-only → preview keeps showcase; keeps monospace).
import './scrambled-text.css';

(window as any).__bz_tx_register('scrambled-text', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (text: string, props: Record<string, unknown>) => {
    root.render(React.createElement(ScrambledText, props as never, text));
  };

  render(textCtx.text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(textCtx.text, newConfig),
    unmount: () => root.unmount(),
  };
});
