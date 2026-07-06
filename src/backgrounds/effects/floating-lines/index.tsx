import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import FloatingLines from '../../vendor-components/FloatingLines';

(window as any).__bz_bg_register('floating-lines', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(FloatingLines, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
