import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import Ballpit from '../../vendor-components/Ballpit';

(window as any).__bz_bg_register('ballpit', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(Ballpit, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
