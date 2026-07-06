import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import Stack from '@hubbee-saas/components/Stack';
import StackCardContent from '@hubbee-saas/components/StackCardContent';
import { mapStack } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

interface StackItem {
  image?: string;
  text?: string;
  background?: { type?: string; color?: string; gradientColors?: [string, string]; gradientAngle?: number };
  focalX?: number;
  focalY?: number;
  cropZoom?: number;
  rotation?: number;
}

const bgStyle = (bg?: StackItem['background']): React.CSSProperties => {
  if (!bg || !bg.type || bg.type === 'none') return {};
  if (bg.type === 'solid' && bg.color) return { background: bg.color };
  if (bg.type === 'gradient' && bg.gradientColors) {
    return { background: `linear-gradient(${bg.gradientAngle ?? 135}deg, ${bg.gradientColors[0]}, ${bg.gradientColors[1]})` };
  }
  return {};
};

registerElementChunk('stack', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapStack(cfg) as Record<string, unknown>;
    const items = (props.items as StackItem[]) ?? [];
    const itemFont = props.itemFont as string | undefined;
    const itemFontSize = props.itemFontSize as number | undefined;
    const itemColor = props.itemColor as string | undefined;
    // cardBackground is rendered as backdrop behind the image only (not
    // filling the card), so we apply it directly on a wrapper around the img.
    const cardBgCss = bgStyle(props.cardBackground as StackItem['background']).background as string | undefined;
    // Card markup + per-item crop bake live in StackCardContent (SSoT shared
    // with the editor preview — items[].imageCrop is applied there).
    const cards = items.map((item, i) =>
      React.createElement(StackCardContent, {
        key: i,
        ...item,
        bgCss: cardBgCss,
        itemFont,
        itemFontSize,
        itemColor,
      }),
    );
    root.render(
      React.createElement(
        'div',
        // Flex-centre the fixed-size stack in the .bz-el-container (was a bare
        // mount → top-left). Mirrors decay-card/pixel-card/profile-card.
        { style: { width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center' } },
        React.createElement(Stack, {
          cards,
          randomRotation: props.randomRotation,
          sensitivity: props.sensitivity,
          sendToBackOnClick: props.sendToBackOnClick,
          autoplay: props.autoplay,
          autoplayDelay: props.autoplayDelay,
          pauseOnHover: props.pauseOnHover,
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
