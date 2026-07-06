import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import SoftAurora from '../../vendor-components/SoftAurora';

(window as any).__bz_bg_register('soft-aurora', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(SoftAurora, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
