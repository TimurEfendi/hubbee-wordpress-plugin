import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import AutoFillScrollVelocity from './AutoFillScrollVelocity';
import { normalizeFxText } from '../../_shared/normalize-text';
import type { TextContext } from '../../_runtime/types';
// WP-only typography reset (chunk-only → preview keeps showcase).
import './scroll-velocity.css';

(window as any).__bz_tx_register('scroll-velocity', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);
  const text = normalizeFxText(textCtx.text);

  const render = (text: string, props: Record<string, unknown>) => {
    // `rows` (default 3) → one alternating-direction marquee row per entry,
    // matching the SaaS preview. The vendor renders one .parallax per `texts`
    // entry and flips direction on odd rows. `rows` is our own knob, not a
    // vendor prop, so strip it before spreading the rest. `numCopies` is no
    // longer a config field — AutoFillScrollVelocity computes it to fill the
    // row seamlessly (and overrides any value left in legacy configs).
    const rows = Math.max(1, Math.min(5, Number(props.rows) || 3));
    const { rows: _omit, ...rest } = props;
    root.render(React.createElement(AutoFillScrollVelocity, { texts: Array.from({ length: rows }, () => text), ...rest }));

    // The runtime sized .bz-tx-layer to a single text line; a multi-row
    // marquee is taller. Reserve the band via min-height so the rows do not
    // overlap following content. We use min-height (not height) because the
    // runtime's ResizeObserver re-sets `height` on every resize but never
    // touches `min-height`, so this is never clobbered.
    requestAnimationFrame(() => {
      const section = container.querySelector('section');
      if (section) container.style.minHeight = `${section.offsetHeight}px`;
    });
  };

  render(text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(text, newConfig),
    unmount: () => root.unmount(),
  };
});
