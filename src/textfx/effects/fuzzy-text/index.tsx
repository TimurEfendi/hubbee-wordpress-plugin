import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import FuzzyMultiline from './FuzzyMultiline';
import type { TextContext } from '../../_runtime/types';
// WP-only positioning for the fuzzy canvases (chunk-only → preview keeps showcase).
import './fuzzy-text.css';

(window as any).__bz_tx_register('fuzzy-text', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (text: string, props: Record<string, unknown>) => {
    // Element typography + available width, so fuzzy renders at the element size
    // and wraps within the column (FuzzyText itself is single-line).
    const fuzzRange = Number(props.fuzzRange) || 30;
    const fuzzPadX = fuzzRange + 20;
    const cs = getComputedStyle(container);
    const ta = cs.textAlign;
    const align = ta === 'center' ? 'center' : (ta === 'end' || ta === 'right') ? 'flex-end' : 'flex-start';

    // The canvases stay in flow (so the host keeps its width); we only shift them
    // visually with translateX to cancel the fuzz padding and align to the element.
    // left → -padX, right → +padX, centre → 0 (the symmetric canvas centres itself).
    const tx = align === 'center' ? 0 : align === 'flex-end' ? fuzzPadX : -fuzzPadX;
    container.style.setProperty('--bz-fuzz-tx', `${tx}px`);

    // FuzzyText draws on a canvas — fillStyle can't resolve currentColor/
    // inherit, so resolve the default to the host element's computed colour
    // (the runtime mirrors it onto the layer).
    const rawColor = props.color;
    const color = (!rawColor || rawColor === 'currentColor' || rawColor === 'inherit')
      ? cs.color
      : rawColor;

    root.render(
      React.createElement(FuzzyMultiline, {
        text,
        fontSize: textCtx.fontSize,
        maxWidth: container.clientWidth || textCtx.originalWidth || Infinity,
        fontFamily: cs.fontFamily,
        lineHeight: parseFloat(cs.lineHeight) || undefined,
        align,
        ...props,
        color,
      }),
    );
  };

  render(textCtx.text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(textCtx.text, newConfig),
    unmount: () => root.unmount(),
  };
});
