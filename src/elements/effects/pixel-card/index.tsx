import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import PixelCard from '@hubbee-saas/components/PixelCard';
import PixelCardContent from '@hubbee-saas/components/PixelCardContent';
import { mapPixelCard } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

registerElementChunk('pixel-card', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapPixelCard(cfg) as Record<string, unknown>;
    const w = (typeof props._width === 'number' ? props._width : 300) as number;
    const h = (typeof props._height === 'number' ? props._height : 400) as number;
    const bg = props.cardBackground as { type?: string; color?: string; gradientColors?: [string, string]; gradientAngle?: number } | undefined;
    const bgCss = (() => {
      if (!bg || !bg.type || bg.type === 'none') return undefined;
      if (bg.type === 'solid' && bg.color) return bg.color;
      if (bg.type === 'gradient' && bg.gradientColors) {
        return `linear-gradient(${bg.gradientAngle ?? 135}deg, ${bg.gradientColors[0]}, ${bg.gradientColors[1]})`;
      }
      return undefined;
    })();
    // strip side-channel (_-prefixed) props before forwarding to the pristine vendor
    const cleanProps: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(props)) {
      if (!k.startsWith('_')) cleanProps[k] = v;
    }
    // Flex-centre the fixed-size card in the .bz-el-container (was inline-block
    // → top-left). Inner sizing div carries --pc-w/--pc-h (override the vendor's
    // hardcoded 300×400) + --hb-card-bg (inherited by PixelCardContent's img).
    const centerStyle: React.CSSProperties = {
      width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center',
    };
    const sizeStyle: React.CSSProperties = {
      width: `${w}px`, height: `${h}px`,
      ['--pc-w' as string]: `${w}px`, ['--pc-h' as string]: `${h}px`,
      ...(bgCss ? { ['--hb-card-bg' as string]: bgCss } : {}),
    };
    root.render(
      React.createElement(
        'div',
        { style: centerStyle },
        React.createElement(
          'div',
          { style: sizeStyle },
          React.createElement(
            PixelCard,
            cleanProps as never,
            React.createElement(PixelCardContent, {
              image: props._image as string | undefined,
              imageCrop: props._imageCrop as { focalX?: number; focalY?: number; zoom?: number; rotation?: number } | undefined,
              imageAsBackground: props._imageAsBackground as boolean | undefined,
              headline: props._headline as string | undefined,
              body: props._body as string | undefined,
              headlineFont: props._headlineFont as string | undefined,
              headlineFontSize: props._headlineFontSize as number | undefined,
              headlineColor: props._headlineColor as string | undefined,
              bodyFont: props._bodyFont as string | undefined,
              bodyFontSize: props._bodyFontSize as number | undefined,
              bodyColor: props._bodyColor as string | undefined,
            }),
          ),
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
