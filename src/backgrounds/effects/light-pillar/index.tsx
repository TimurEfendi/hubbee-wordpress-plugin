import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import LightPillar from '../../vendor-components/LightPillar';

(window as any).__bz_bg_register('light-pillar', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    root.render(React.createElement(LightPillar, props));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
