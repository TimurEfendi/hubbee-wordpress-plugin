import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import DomeGallery from '@hubbee-saas/components/DomeGallery';
import ElementHeadlineFrame from '@hubbee-saas/components/ElementHeadlineFrame';
import { mapDomeGallery } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

registerElementChunk('dome-gallery', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapDomeGallery(cfg);
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
        React.createElement(DomeGallery, props as never),
      ),
    );
  };
  render(rawConfig);
  return {
    update: (cfg: Record<string, unknown>) => render(cfg),
    unmount: () => root.unmount(),
  };
});
