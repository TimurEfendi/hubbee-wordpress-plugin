import React, { useMemo } from 'react';
import FuzzyText from '../../vendor-components/FuzzyText';

/**
 * Multi-line wrapper around the 1:1 reactbits FuzzyText.
 *
 * FuzzyText draws its whole string on a single canvas line (no wrapping) and
 * insets the text by its fuzz padding (horizontalMargin = fuzzRange + 20). This
 * adapter greedily word-wraps the text to the available width (measured at the
 * element font) and renders ONE FuzzyText per line, stacked in a flex column.
 *
 * Layout note: the canvases stay IN FLOW (display:block) so they give the
 * runtime layer / Elementor host its width. Earlier attempts that took them out
 * of flow — negative margins (shrank the host) or absolute positioning
 * (collapsed the host to 0 width) — broke the layout. Alignment to the element
 * (cancelling the fuzz padding) is done purely with `transform: translateX(...)`
 * in fuzzy-text.css, which is visual-only and never affects layout.
 */
const wrapLines = (text: string, font: string, maxWidth: number): string[] => {
  if (!maxWidth || !isFinite(maxWidth)) return [text];
  const ctx = document.createElement('canvas').getContext('2d');
  if (!ctx) return [text];
  ctx.font = font;
  const words = text.replace(/\s+/g, ' ').trim().split(' ').filter(Boolean);
  const lines: string[] = [];
  let cur = '';
  for (const w of words) {
    const test = cur ? `${cur} ${w}` : w;
    if (cur && ctx.measureText(test).width > maxWidth) {
      lines.push(cur);
      cur = w;
    } else {
      cur = test;
    }
  }
  if (cur) lines.push(cur);
  return lines.length ? lines : [text];
};

interface FuzzyMultilineProps {
  text: string;
  fontSize: number;
  maxWidth?: number;
  fontFamily?: string;
  fontWeight?: number | string;
  lineHeight?: number;
  /** Flex value derived from the element's text-align ('flex-start' | 'center' | 'flex-end'). */
  align?: string;
  [key: string]: unknown;
}

export default function FuzzyMultiline({
  text,
  fontSize,
  maxWidth,
  fontFamily = 'sans-serif',
  fontWeight = 900,
  lineHeight,
  align = 'flex-start',
  ...rest
}: FuzzyMultilineProps) {
  const lines = useMemo(
    () => wrapLines(text, `${fontWeight} ${fontSize}px ${fontFamily}`, maxWidth ?? Infinity),
    [text, fontSize, maxWidth, fontFamily, fontWeight],
  );

  // Approximate inter-line spacing so multiple lines sit at the element's
  // line-height (each canvas is ~fontSize tall for horizontal fuzz).
  const gap = lineHeight && lineHeight > fontSize ? `${Math.round(lineHeight - fontSize)}px` : undefined;

  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: align, rowGap: gap }}>
      {lines.map((ln, i) => (
        <FuzzyText key={i} fontSize={fontSize} fontWeight={fontWeight} {...rest} className="bz-fuzzy-canvas">
          {ln}
        </FuzzyText>
      ))}
    </div>
  );
}
