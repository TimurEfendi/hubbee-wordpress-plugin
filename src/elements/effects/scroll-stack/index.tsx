import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import ScrollStack, { ScrollStackItem } from '@hubbee-saas/components/ScrollStack';
import ScrollStackCardContent from '@hubbee-saas/components/ScrollStackCardContent';
import { mapScrollStack } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

interface ScrollStackItemConfig {
  title?: string;
  description?: string;
  image?: string;
  focalX?: number;
  focalY?: number;
  cropZoom?: number;
  rotation?: number;
}

registerElementChunk('scroll-stack', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapScrollStack(cfg) as Record<string, unknown>;
    const items = (props._items as ScrollStackItemConfig[] | undefined) ?? [];
    // Strip side-channel _* props.
    const cleanProps: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(props)) {
      if (!k.startsWith('_') && k !== 'cardBackground') cleanProps[k] = v;
    }
    const bg = props.cardBackground as { type?: string; color?: string; gradientColors?: [string, string]; gradientAngle?: number } | undefined;
    const bgCss = (() => {
      if (!bg || !bg.type || bg.type === 'none') return undefined;
      if (bg.type === 'solid' && bg.color) return bg.color;
      if (bg.type === 'gradient' && bg.gradientColors) {
        return `linear-gradient(${bg.gradientAngle ?? 135}deg, ${bg.gradientColors[0]}, ${bg.gradientColors[1]})`;
      }
      return undefined;
    })();
    // Outer wrapper propagates `--hb-card-bg` only — no background fill.
    // Image-slot backdrop is applied per ScrollStackItem below.
    // CRITICAL: width/height 100% + overflow hidden — the ScrollStack scroller
    // is `height:100%; overflow-y:auto` and needs a fixed-height parent (the
    // ~600px .bz-el-container). Without it the scroller grew to its content
    // height (no overflow → no internal scroll → the stacking effect never
    // ran on WP). Mirrors the editor preview's `inset:0; overflow:hidden` box.
    const wrapBg: React.CSSProperties = {
      width: '100%',
      height: '100%',
      overflow: 'hidden',
      ...(bgCss ? { ['--hb-card-bg' as string]: bgCss } : {}),
      // Card corner radius (consumed by .scroll-stack-card via var(--ss-radius)).
      ...(typeof props._borderRadius === 'number' ? { ['--ss-radius' as string]: `${props._borderRadius}px` } : {}),
    };
    root.render(
      React.createElement(
        'div',
        { style: wrapBg },
      React.createElement(
        ScrollStack,
        cleanProps as never,
        // Card markup + per-item crop bake live in ScrollStackCardContent
        // (SSoT shared with the editor preview — items[].imageCrop applies).
        ...items.map((it, i) =>
          React.createElement(
            ScrollStackItem as React.ComponentType<React.PropsWithChildren<{ itemClassName?: string }>>,
            { key: i },
            React.createElement(ScrollStackCardContent, {
              ...it,
              bgCss,
              titleFont: props._titleFont as string | undefined,
              titleFontSize: props._titleFontSize as number | undefined,
              titleColor: props._titleColor as string | undefined,
              descriptionFont: props._descriptionFont as string | undefined,
              descriptionFontSize: props._descriptionFontSize as number | undefined,
              descriptionColor: props._descriptionColor as string | undefined,
            }),
          ),
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
