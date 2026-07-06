import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import MagicBento from '@hubbee-saas/components/MagicBento';
import { mapMagicBento } from '@hubbee-shared/elements/section-to-vendor';
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

registerElementChunk('magic-bento', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapMagicBento(cfg) as Record<string, unknown>;
    const bgCss = cardBgToCss(props.cardBackground as CardBg | undefined);
    // Outer wrapper propagates `--hb-card-bg`; MagicBento vendor renders an
    // absolute backdrop div behind each `.magic-bento-img` so transparent
    // images show the gradient. Cards themselves keep their dark intrinsic
    // background to preserve the spotlight effect contrast.
    const wrapStyle: React.CSSProperties = bgCss
      ? { ['--hb-card-bg' as string]: bgCss }
      : {};
    root.render(
      React.createElement('div', { style: wrapStyle }, React.createElement(MagicBento, props as never)),
    );
  };
  render(rawConfig);
  return {
    update: (cfg: Record<string, unknown>) => render(cfg),
    unmount: () => root.unmount(),
  };
});
