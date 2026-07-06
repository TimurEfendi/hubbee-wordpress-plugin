import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import Hyperspeed from '../../vendor-components/Hyperspeed';

(window as any).__bz_bg_register('hyperspeed', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(Hyperspeed, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
