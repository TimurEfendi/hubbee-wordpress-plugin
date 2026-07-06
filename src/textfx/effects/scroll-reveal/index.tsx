import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import ScrollReveal from '../../vendor-components/ScrollReveal';
import type { TextContext } from '../../_runtime/types';
// WP-only typography reset (chunk-only → preview keeps showcase).
import './scroll-reveal.css';

(window as any).__bz_tx_register('scroll-reveal', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (text: string, props: Record<string, unknown>) => {
    // The vendor's default scrub windows (start 'top bottom-=20%' / end
    // 'bottom bottom') are written for tall demo paragraphs. For a typical
    // heading SHORTER than 20% of the viewport the word/blur window is
    // INVERTED (end scrolls past before start) → the scrub never plays and
    // the text stays at baseOpacity (looks permanently washed out). Use
    // element-TOP-based ends so the window is valid at any element height.
    // clamp() keeps the range inside the page's reachable scroll span
    // (elements near the end of a page could otherwise never complete).
    root.render(React.createElement(ScrollReveal, {
      rotationEnd: 'clamp(top bottom-=45%)',
      wordAnimationEnd: 'clamp(top bottom-=45%)',
      ...props,
    } as never, text));
  };

  render(textCtx.text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(textCtx.text, newConfig),
    unmount: () => root.unmount(),
  };
});
