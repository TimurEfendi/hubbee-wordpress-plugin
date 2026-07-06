import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import GlitchText from '../../vendor-components/GlitchText';
import type { TextContext } from '../../_runtime/types';
// WP-only typography reset (chunk-only → preview keeps showcase).
import './glitch-text.css';

(window as any).__bz_tx_register('glitch-text', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (text: string, props: Record<string, unknown>) => {
    root.render(React.createElement(GlitchText, props as never, text));
  };

  render(textCtx.text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(textCtx.text, newConfig),
    unmount: () => root.unmount(),
  };
});
