import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import LiquidChrome from '../../vendor-components/LiquidChrome';
import { hexToRgb } from '../../utils';

(window as any).__bz_bg_register('liquid-chrome', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    const mapped = {
      ...props,
      baseColor: typeof props.baseColor === 'string' ? hexToRgb(props.baseColor as string) : props.baseColor,
    };
    root.render(React.createElement(LiquidChrome, mapped));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
