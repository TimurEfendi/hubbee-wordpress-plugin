import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import TiltedCard from '@hubbee-saas/components/TiltedCard';
import { mapTiltedCard } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

registerElementChunk('tilted-card', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) =>
    root.render(React.createElement(TiltedCard, mapTiltedCard(cfg) as never));
  render(rawConfig);
  return {
    update: (cfg: Record<string, unknown>) => render(cfg),
    unmount: () => root.unmount(),
  };
});
