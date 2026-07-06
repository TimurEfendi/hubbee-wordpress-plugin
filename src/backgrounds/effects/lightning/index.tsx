import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import Lightning from '../../vendor-components/Lightning';

(window as any).__bz_bg_register('lightning', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(Lightning, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
