import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import RippleGrid from '../../vendor-components/RippleGrid';

(window as any).__bz_bg_register('ripple-grid', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(RippleGrid, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
