import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import DecayCard from '@hubbee-saas/components/DecayCard';
import { mapDecayCard } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

interface DecayCardChunkProps {
  image?: string;
  width?: number;
  height?: number;
  headline?: string;
  body?: string;
  headlineFont?: string;
  headlineFontSize?: number;
  headlineColor?: string;
  bodyFont?: string;
  bodyFontSize?: number;
  bodyColor?: string;
  cardBackground?: { type?: string; color?: string; gradientColors?: [string, string]; gradientAngle?: number };
}

const bgStyle = (bg?: DecayCardChunkProps['cardBackground']): React.CSSProperties => {
  if (!bg || bg.type === 'none' || !bg.type) return {};
  if (bg.type === 'solid' && bg.color) return { background: bg.color };
  if (bg.type === 'gradient' && bg.gradientColors) {
    return { background: `linear-gradient(${bg.gradientAngle ?? 135}deg, ${bg.gradientColors[0]}, ${bg.gradientColors[1]})` };
  }
  return {};
};

registerElementChunk('decay-card', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapDecayCard(cfg) as DecayCardChunkProps;
    const headlineStyle: React.CSSProperties = {
      fontFamily: props.headlineFont ?? undefined,
      fontSize: props.headlineFontSize ? `${props.headlineFontSize}px` : undefined,
      color: props.headlineColor ?? undefined,
      margin: 0,
    };
    const bodyStyle: React.CSSProperties = {
      fontFamily: props.bodyFont ?? undefined,
      fontSize: props.bodyFontSize ? `${props.bodyFontSize}px` : undefined,
      color: props.bodyColor ?? undefined,
      margin: 0,
    };
    // Outer wrapper propagates `--hb-card-bg` only. DecayCard.css applies it
    // as backdrop on `.decay-card-content` so transparent PNGs show the
    // gradient behind the SVG image (scope-tight to image area).
    const bgCss = bgStyle(props.cardBackground).background;
    const wrapperStyle: React.CSSProperties = {
      borderRadius: '12px',
      display: 'inline-block',
      ...(bgCss ? { ['--hb-card-bg' as string]: bgCss as string } : {}),
    };
    // Flex-centre the fixed-size card inside the mountpoint's .bz-el-container
    // (height:100% → ~600px default) so it sits CENTRED, not top-left — the bare
    // inline-block mount was the cause of the reported left/top alignment.
    // Mirrors card-swap/bounce-cards.
    const centerStyle: React.CSSProperties = {
      width: '100%',
      height: '100%',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
    };
    root.render(
      React.createElement(
        'div',
        { style: centerStyle },
        React.createElement(
          'div',
          { style: wrapperStyle },
          React.createElement(
            DecayCard,
            { image: props.image, width: props.width, height: props.height },
            props.headline ? React.createElement('h3', { style: headlineStyle }, props.headline) : null,
            props.body ? React.createElement('p', { style: bodyStyle }, props.body) : null,
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
