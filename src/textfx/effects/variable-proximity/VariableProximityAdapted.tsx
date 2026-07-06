// Hubbee adapter for VariableProximity. The vendor REQUIRES a containerRef —
// its rAF loop bails out entirely without one (`if (!containerRef?.current)
// return`), so rendering the raw vendor leaves the hover interpolation dead.
// It also translates the user-facing `weightFrom`/`weightTo` sliders into the
// raw font-variation-settings strings the vendor expects (no average user
// understands `'wght' 400, 'opsz' 9`). Legacy configs that still carry the
// raw string keys keep working and take precedence until re-saved (the asset
// schema strips them on the next save).
// Shared by the WP chunk (effects/variable-proximity/index.tsx) and the SaaS
// TextEffectPreview so both surfaces render identically. Vendor stays 1:1.
import React, { useRef } from 'react';
import VariableProximity from '../../vendor-components/VariableProximity';

interface WrapperProps {
  label: string;
  weightFrom?: number;
  weightTo?: number;
  fromFontVariationSettings?: string;
  toFontVariationSettings?: string;
  [key: string]: unknown;
}

const clampWeight = (v: unknown, fallback: number) => {
  const n = Number(v);
  return Number.isFinite(n) && n > 0 ? Math.min(1000, Math.max(100, n)) : fallback;
};

const VariableProximityAdapted: React.FC<WrapperProps> = ({
  label,
  weightFrom,
  weightTo,
  fromFontVariationSettings,
  toFontVariationSettings,
  ...rest
}) => {
  const containerRef = useRef<HTMLDivElement>(null);
  const from = fromFontVariationSettings || `'wght' ${clampWeight(weightFrom, 400)}, 'opsz' 9`;
  const to = toFontVariationSettings || `'wght' ${clampWeight(weightTo, 1000)}, 'opsz' 40`;
  return React.createElement(
    'div',
    { ref: containerRef, style: { position: 'relative' } },
    React.createElement(VariableProximity, {
      label,
      fromFontVariationSettings: from,
      toFontVariationSettings: to,
      containerRef: containerRef as React.RefObject<HTMLElement>,
      ...rest,
    })
  );
};

export default VariableProximityAdapted;
