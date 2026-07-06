import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import BounceCards from '@hubbee-saas/components/BounceCards';
import ElementHeadlineFrame from '@hubbee-saas/components/ElementHeadlineFrame';
import { mapBounceCards } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

registerElementChunk('bounce-cards', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapBounceCards(cfg);
    root.render(
      React.createElement(
        ElementHeadlineFrame,
        {
          headline: props.headline,
          headlineFont: props.headlineFont,
          headlineFontSize: props.headlineFontSize,
          headlineColor: props.headlineColor,
          headerGap: props.headerGap,
        },
        // Flex-centre the fanned deck in the frame's flex:1 region (600px
        // mountpoint) so it sits CENTRED, not top-aligned in the upper part.
        React.createElement(
          'div',
          { style: { width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center' } },
          React.createElement(BounceCards, props as never),
        ),
      ),
    );
  };
  render(rawConfig);
  return {
    update: (cfg: Record<string, unknown>) => render(cfg),
    unmount: () => root.unmount(),
  };
});
