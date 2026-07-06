// Hubbee adapter for TrueFocus. Co-loads our slug-specific override CSS
// (font inherits from the WP element; Glow Color control wired up; stray
// theme borders reset) AND scales the blur to the rendered font-size, so the
// blur:text ratio is identical in the configurator and on the WP frontend.
// Imported by BOTH the WP chunk (effects/true-focus/index.tsx) and the SaaS
// preview (TextEffectPreview) so they render identically. Vendor stays 1:1.
import './true-focus.css';
import React, { useRef, useState, useLayoutEffect } from 'react';
import TrueFocusVendor from '../../vendor-components/TrueFocus';

// Loose-cast the vendor: TrueFocus.tsx is typed (unlike the @ts-nocheck
// ScrollVelocity), and the app's React typings skew makes its FC type fail as
// a JSX element. The runtime behaviour is unchanged.
const TrueFocus = TrueFocusVendor as unknown as React.FC<Record<string, unknown>>;

// blurAmount is authored against the configurator's 48px preview; scale it by
// the actually-rendered word font-size so the blur looks the same relative to
// the text on WP (where the size comes from the heading element).
const REFERENCE_FONT_PX = 48;

export default function TrueFocusAdapted({
  blurAmount = 5,
  ...props
}: { blurAmount?: number } & Record<string, unknown>) {
  const ref = useRef<HTMLDivElement>(null);
  const [fontPx, setFontPx] = useState(REFERENCE_FONT_PX);

  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    const measure = () => {
      const word = el.querySelector<HTMLElement>('.focus-word');
      const fs = word ? parseFloat(getComputedStyle(word).fontSize) : 0;
      if (fs > 0) setFontPx((prev) => (Math.abs(prev - fs) < 0.5 ? prev : fs));
    };
    measure();
    const container = el.querySelector('.focus-container');
    const ro = container ? new ResizeObserver(measure) : null;
    if (container && ro) ro.observe(container);
    return () => ro?.disconnect();
  }, []);

  const scaledBlur = Math.max(0, (Number(blurAmount) || 0) * (fontPx / REFERENCE_FONT_PX));

  return (
    <div ref={ref} style={{ display: 'contents' }}>
      <TrueFocus blurAmount={scaledBlur} {...props} />
    </div>
  );
}
