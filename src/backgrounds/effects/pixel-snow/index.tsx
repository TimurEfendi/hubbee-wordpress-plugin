import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import PixelSnow from '../../vendor-components/PixelSnow';

(window as any).__bz_bg_register('pixel-snow', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(PixelSnow, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
