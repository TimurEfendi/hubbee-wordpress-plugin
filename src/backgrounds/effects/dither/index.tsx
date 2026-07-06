import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import Dither from '../../vendor-components/Dither';
import { hexToRgb } from '../../utils';

(window as any).__bz_bg_register('dither', (container: HTMLElement, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (props: Record<string, unknown>) => {
    const mapped = {
      ...props,
      waveColor: typeof props.waveColor === 'string' ? hexToRgb(props.waveColor as string) : props.waveColor,
    };
    root.render(React.createElement(Dither, mapped));
  };

  render(config);

  return {
    update: (newConfig: Record<string, unknown>) => render(newConfig),
    unmount: () => root.unmount(),
  };
});
