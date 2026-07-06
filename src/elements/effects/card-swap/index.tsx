import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import CardSwap from '@hubbee-saas/components/CardSwap';
import { mapCardSwap } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

interface CardBg { type?: string; color?: string; gradientColors?: [string, string]; gradientAngle?: number }
const cardBgToCss = (bg?: CardBg): string | undefined => {
  if (!bg || !bg.type || bg.type === 'none') return undefined;
  if (bg.type === 'solid' && bg.color) return bg.color;
  if (bg.type === 'gradient' && bg.gradientColors) {
    return `linear-gradient(${bg.gradientAngle ?? 135}deg, ${bg.gradientColors[0]}, ${bg.gradientColors[1]})`;
  }
  return undefined;
};

registerElementChunk('card-swap', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapCardSwap(cfg) as Record<string, unknown>;
    const bgCss = cardBgToCss(props.cardBackground as CardBg | undefined);
    // Flex-centre the (self-contained) element inside the mountpoint's
    // .bz-el-container (height:100% → 600px default) so it sits CENTRED, not
    // top-aligned — the top-aligned inline-block was the cause of the reported
    // "sits too high / clips at the top". Also propagates `--hb-card-bg`.
    const wrapStyle: React.CSSProperties = {
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      width: '100%',
      height: '100%',
      ...(bgCss ? { ['--hb-card-bg' as string]: bgCss } : {}),
    };
    root.render(
      React.createElement('div', { style: wrapStyle }, React.createElement(CardSwap, props as never)),
    );
  };
  render(rawConfig);
  return {
    update: (cfg: Record<string, unknown>) => render(cfg),
    unmount: () => root.unmount(),
  };
});
