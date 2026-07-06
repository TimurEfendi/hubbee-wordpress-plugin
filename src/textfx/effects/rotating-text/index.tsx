import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import RotatingText from '../../vendor-components/RotatingText';
import type { TextContext } from '../../_runtime/types';

// RotatingText cycles through the `texts[]` array. The WP element only carries
// one text, so we derive the rotation items from it using `splitBy` (the user's
// choice): words → cycle each word, lines → each line, characters → each char.
// Whitespace is normalised so no empty items rotate.
const toRotationItems = (text: string, splitBy: string): string[] => {
  const t = (text || '').replace(/\s+/g, ' ').trim();
  if (!t) return [text];
  if (splitBy === 'lines') {
    const lines = (text || '').split('\n').map(s => s.trim()).filter(Boolean);
    return lines.length ? lines : [t];
  }
  if (splitBy === 'characters') return Array.from(t);
  return t.split(' ').filter(Boolean); // 'words' (default)
};

(window as any).__bz_tx_register('rotating-text', (container: HTMLElement, textCtx: TextContext, config: Record<string, unknown>) => {
  const root: Root = createRoot(container);

  const render = (text: string, props: Record<string, unknown>) => {
    const splitBy = (props.splitBy as string) || 'words';
    root.render(React.createElement(RotatingText, { texts: toRotationItems(text, splitBy), ...props }));
  };

  render(textCtx.text, config);

  return {
    update: (newConfig: Record<string, unknown>) => render(textCtx.text, newConfig),
    unmount: () => root.unmount(),
  };
});
