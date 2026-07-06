import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import SpotlightCard from '@hubbee-saas/components/SpotlightCard';
import { mapSpotlightCard } from '@hubbee-shared/elements/section-to-vendor';
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

registerElementChunk('spotlight-card', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapSpotlightCard(cfg) as Record<string, unknown>;
    const bgCss = cardBgToCss(props.cardBackground as CardBg | undefined);
    const wrapStyle: React.CSSProperties = bgCss
      ? { display: 'inline-block', ['--hb-card-bg' as string]: bgCss }
      : { display: 'inline-block' };
    // Flex-centre the fixed-size card in the .bz-el-container (the bare
    // inline-block mount rendered top-left). Mirrors decay-card/profile-card.
    const centerStyle: React.CSSProperties = {
      width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center',
    };
    // Content markup + crop bake live in SpotlightCard itself (SSoT — the
    // editor preview passes the same mapped props).
    const { cardBackground: _cardBackground, ...cardProps } = props;
    void _cardBackground;
    root.render(
      React.createElement(
        'div',
        { style: centerStyle },
        React.createElement(
          'div',
          { style: wrapStyle },
          // eslint-disable-next-line @typescript-eslint/no-explicit-any
          React.createElement(SpotlightCard as React.ComponentType<any>, cardProps),
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
