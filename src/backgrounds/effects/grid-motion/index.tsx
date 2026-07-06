import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import GridMotion from '../../vendor-components/GridMotion';

(window as any).__bz_bg_register('grid-motion', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(GridMotion, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
