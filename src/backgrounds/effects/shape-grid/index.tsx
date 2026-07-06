import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import ShapeGrid from '../../vendor-components/ShapeGrid';

(window as any).__bz_bg_register('shape-grid', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(ShapeGrid, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
