import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import BorderGlow from '@hubbee-saas/components/BorderGlow';
import { mapBorderGlow } from '@hubbee-shared/elements/section-to-vendor';
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

// The vendor BorderGlow renders its own inner content (image / headline / body,
// both the top-slot AND the full-background `imageAsBackground` mode) from these
// props — the SINGLE source of truth shared with the editor preview. The chunk
// only forwards the image-slot background via the `--hb-card-bg` wrapper var.
registerElementChunk('border-glow', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapBorderGlow(cfg) as Record<string, unknown>;
    const bgCss = cardBgToCss(props.cardBackground as CardBg | undefined);
    const wrapStyle: React.CSSProperties = bgCss
      ? { display: 'inline-block', ['--hb-card-bg' as string]: bgCss }
      : { display: 'inline-block' };
    root.render(
      React.createElement(
        'div',
        { style: wrapStyle },
        React.createElement(BorderGlow, {
          width: props.width as number | undefined,
          height: props.height as number | undefined,
          glowColor: props.glowColor as string | undefined,
          glowSpeed: props.glowSpeed as number | undefined,
          glowSize: props.glowSize as number | undefined,
          borderRadius: props.borderRadius as number | undefined,
          headline: props.headline as string | undefined,
          body: props.body as string | undefined,
          image: props.image as string | undefined,
          imageAsBackground: props.imageAsBackground as boolean | undefined,
          headlineFont: props.headlineFont as string | undefined,
          headlineFontSize: props.headlineFontSize as number | undefined,
          headlineColor: props.headlineColor as string | undefined,
          bodyFont: props.bodyFont as string | undefined,
          bodyFontSize: props.bodyFontSize as number | undefined,
          bodyColor: props.bodyColor as string | undefined,
        } as never),
      ),
    );
  };
  render(rawConfig);
  return {
    update: (cfg: Record<string, unknown>) => render(cfg),
    unmount: () => root.unmount(),
  };
});
