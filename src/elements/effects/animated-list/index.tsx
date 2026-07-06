import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import AnimatedList from '@hubbee-saas/components/AnimatedList';
import { mapAnimatedList } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

registerElementChunk('animated-list', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapAnimatedList(cfg) as Record<string, unknown>;
    root.render(React.createElement(AnimatedList, props as never));
  };
  render(rawConfig);
  return {
    update: (cfg: Record<string, unknown>) => render(cfg),
    unmount: () => root.unmount(),
  };
});
