import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import ScrollFloat from '../../vendor-components/ScrollFloat';
import { normalizeFxText } from '../../_shared/normalize-text';
import type { TextContext } from '../../_runtime/types';
// WP-only typography reset (chunk-only → preview keeps showcase).
import './scroll-float.css';

(window as any).__bz_tx_register('scroll-float', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);
  const text = normalizeFxText(textCtx.text);

  const render = (text: string, props: Record<string, unknown>) => {
    // Wrap the scrub range in ScrollTrigger's clamp() so it stays inside the
    // page's reachable scroll range. Without it, an element near the END of a
    // page can never scroll far enough for 'bottom bottom-=40%' → with a long
    // text + per-char stagger the tail chars stay at opacity 0 (unreadable).
    const clampPos = (v: unknown, fallback: string) => {
      const s = typeof v === 'string' && v.trim() ? v.trim() : fallback;
      return s.startsWith('clamp(') ? s : `clamp(${s})`;
    };
    root.render(React.createElement(ScrollFloat, {
      ...props,
      scrollStart: clampPos(props.scrollStart, 'center bottom+=50%'),
      scrollEnd: clampPos(props.scrollEnd, 'bottom bottom-=40%'),
    } as never, text));
  };

  render(text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(text, newConfig),
    unmount: () => root.unmount(),
  };
});
