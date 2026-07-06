import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import Iridescence from '../../vendor-components/Iridescence';
import { hexToRgb } from '../../utils';

(window as any).__bz_bg_register('iridescence', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    const mapped = {
      ...props,
      color: typeof props.color === 'string' ? hexToRgb(props.color as string) : props.color,
    };
    root.render(React.createElement(Iridescence, mapped));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
