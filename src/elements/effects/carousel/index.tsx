import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import Carousel from '@hubbee-saas/components/Carousel';
import { mapCarousel } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

// The SaaS Carousel wrapper (src/components/Carousel) is the single source of
// truth for prop→style mapping: per-item crop + background, item typography, and
// the outer-frame toggle/colour/opacity. The chunk just maps config → props.
registerElementChunk('carousel', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapCarousel(cfg);
    root.render(
      React.createElement(
        'div',
        // Flex-centre the fixed-width carousel in the .bz-el-container (bare
        // mount rendered top-left). Mirrors decay-card/pixel-card/stack/etc.
        { style: { width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center' } },
        React.createElement(Carousel, props as never),
      ),
    );
  };
  render(rawConfig);
  return {
    update: (cfg: Record<string, unknown>) => render(cfg),
    unmount: () => root.unmount(),
  };
});
