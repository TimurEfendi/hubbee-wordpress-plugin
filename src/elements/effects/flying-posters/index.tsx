import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import FlyingPosters from '@hubbee-saas/components/FlyingPosters';
import { mapFlyingPosters } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

registerElementChunk('flying-posters', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) =>
    root.render(React.createElement(FlyingPosters, mapFlyingPosters(cfg) as never));
  render(rawConfig);
  return {
    update: (cfg: Record<string, unknown>) => render(cfg),
    unmount: () => root.unmount(),
  };
});
