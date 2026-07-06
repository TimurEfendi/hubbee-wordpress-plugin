import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import Beams from '../../vendor-components/Beams';

(window as any).__bz_bg_register('beams', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(Beams, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
