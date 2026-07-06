import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import LiquidEther from '../../vendor-components/LiquidEther';

(window as any).__bz_bg_register('liquid-ether', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(LiquidEther, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
