import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
// Shared adapter (also used by the SaaS TextEffectPreview): wraps the vendor
// with the containerRef it requires for its proximity loop.
import VariableProximityWrapper from './VariableProximityAdapted';
import type { TextContext } from '../../_runtime/types';

(window as any).__bz_tx_register('variable-proximity', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (text: string, props: Record<string, unknown>) => {
    root.render(React.createElement(VariableProximityWrapper, { label: text, ...props }));
  };

  render(textCtx.text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(textCtx.text, newConfig),
    unmount: () => root.unmount(),
  };
});
