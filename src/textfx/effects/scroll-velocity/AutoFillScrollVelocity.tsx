import React, { useRef, useState, useLayoutEffect } from 'react';
import ScrollVelocity from '../../vendor-components/ScrollVelocity';

/**
 * Auto-filling wrapper around the 1:1 ReactBits ScrollVelocity.
 *
 * ScrollVelocity loops the strip within ONE copy's width, so a seamless
 * marquee needs enough copies to exceed the container width by one copy
 * (`numCopies × copyWidth ≥ containerWidth + copyWidth`). Upstream ships a
 * fixed `numCopies` (default 6) which tears when too low for the text/width.
 * Rather than exposing that as a knob, we measure the rendered copy width vs
 * the container width and compute just enough copies — pre-paint via
 * useLayoutEffect (no gap flash) and on resize via a ResizeObserver.
 *
 * Shared by the WP chunk (effects/scroll-velocity/index.tsx) and the SaaS
 * TextEffectPreview so both render identically. The vendor stays untouched.
 */
export default function AutoFillScrollVelocity({
  texts,
  ...props
}: { texts: React.ReactNode[] } & Record<string, unknown>) {
  const wrapRef = useRef<HTMLDivElement>(null);
  const [numCopies, setNumCopies] = useState(6);

  useLayoutEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    const compute = () => {
      const containerW = el.offsetWidth || 0;
      const span = el.querySelector<HTMLElement>('.scroller span');
      const copyW = span?.offsetWidth || 0;
      if (containerW > 0 && copyW > 0) {
        const needed = Math.min(60, Math.max(2, Math.ceil(containerW / copyW) + 1));
        setNumCopies((prev) => (prev === needed ? prev : needed));
      }
    };
    compute();
    const ro = new ResizeObserver(compute);
    ro.observe(el);
    return () => ro.disconnect();
  }, [texts]);

  // Spread props FIRST so our computed numCopies (and texts) always win over
  // any stale `numCopies` left in legacy saved configs.
  return (
    <div ref={wrapRef} style={{ width: '100%' }}>
      <ScrollVelocity {...props} texts={texts} numCopies={numCopies} />
    </div>
  );
}
