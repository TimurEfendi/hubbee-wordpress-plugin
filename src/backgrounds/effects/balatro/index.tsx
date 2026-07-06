import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import Balatro from '../../vendor-components/Balatro';

(window as any).__bz_bg_register('balatro', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(Balatro, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
