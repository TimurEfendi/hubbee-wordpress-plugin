import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import Stepper from '@hubbee-saas/components/Stepper';
import { mapStepper } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

registerElementChunk('stepper', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapStepper(cfg) as Record<string, unknown>;
    root.render(
      React.createElement(
        'div',
        // Flex-centre the fixed-width wizard in the .bz-el-container (was a
        // bare mount → top-left). Mirrors decay-card/pixel-card/profile-card.
        { style: { width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center' } },
        React.createElement(Stepper, props as never),
      ),
    );
  };
  render(rawConfig);
  return {
    update: (cfg: Record<string, unknown>) => render(cfg),
    unmount: () => root.unmount(),
  };
});
