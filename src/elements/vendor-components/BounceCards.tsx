/** Source: react-bits by DavidHDev (MIT) — Hubbee-adapted: `cardSize` prop
 *  drives the card size (via the --bc-card-size CSS var) and scales the fan
 *  translates + hover push proportionally; the container is sized to the fan's
 *  bounding box so the element is self-contained and measurable (ScaleToFit). */

import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';
import './BounceCards.css';

interface BounceCardsProps {
  className?: string;
  images?: string[];
  /** Hubbee extension: per-card caption overlay, index-aligned with images. */
  captions?: string[];
  containerWidth?: number;
  containerHeight?: number;
  cardSize?: number;
  animationDelay?: number;
  animationStagger?: number;
  easeType?: string;
  transformStyles?: string[];
  enableHover?: boolean;
}

const PLACEHOLDER_IMAGES = [
  'https://images.unsplash.com/photo-1506744038136-46273834b3fb?w=400&h=400&fit=crop',
  'https://images.unsplash.com/photo-1511884642898-4c92249e20b6?w=400&h=400&fit=crop',
  'https://images.unsplash.com/photo-1469474968028-56623f02e42e?w=400&h=400&fit=crop',
  'https://images.unsplash.com/photo-1447752875215-b2761acb3c5d?w=400&h=400&fit=crop',
  'https://images.unsplash.com/photo-1472214103451-9374bd1c798e?w=400&h=400&fit=crop',
];

const DEFAULT_TRANSFORMS = [
  'rotate(10deg) translate(-170px)',
  'rotate(5deg) translate(-85px)',
  'rotate(-3deg)',
  'rotate(-10deg) translate(85px)',
  'rotate(2deg) translate(170px)',
];

// Scale every `translate(Npx)` in a transform string by `s` (keeps rotate etc.).
const scaleTranslate = (t: string, s: number): string =>
  t.replace(/translate\((-?[\d.]+)px\)/g, (_m, x) => `translate(${(parseFloat(x) * s).toFixed(2)}px)`);

export default function BounceCards({
  className = '',
  images: rawImages = [],
  captions,
  containerWidth,
  containerHeight,
  cardSize = 200,
  animationDelay = 0.5,
  animationStagger = 0.06,
  easeType = 'elastic.out(1, 0.8)',
  transformStyles = DEFAULT_TRANSFORMS,
  enableHover = true,
}: BounceCardsProps) {
  const images = (rawImages && rawImages.length > 0) ? rawImages : PLACEHOLDER_IMAGES;
  const containerRef = useRef<HTMLDivElement>(null);

  // Proportional fan: the default translates are tuned for a 200px card.
  const s = (cardSize || 200) / 200;
  const scaledTransforms = transformStyles.map(t => scaleTranslate(t, s));
  const scaledHover = 160 * s;

  // Size the container to the fan's bounding box (cards are centred then
  // translated ±maxT). Then offsetWidth/Height reflect the real visual size,
  // so ScaleToFit in the editor preview fits the whole element correctly.
  const maxT = Math.max(0, ...scaledTransforms.map(t => {
    const m = t.match(/translate\((-?[\d.]+)px\)/);
    return m ? Math.abs(parseFloat(m[1])) : 0;
  }));
  const PAD = 24;
  const cw = containerWidth ?? Math.round(2 * maxT + cardSize + PAD * 2);
  const ch = containerHeight ?? Math.round(cardSize * 1.4 + PAD * 2);

  useEffect(() => {
    const ctx = gsap.context(() => {
      gsap.fromTo(
        '.card',
        { scale: 0 },
        {
          scale: 1,
          stagger: animationStagger,
          ease: easeType,
          delay: animationDelay,
        }
      );
    }, containerRef);
    return () => ctx.revert();
  }, [animationStagger, easeType, animationDelay]);

  const getNoRotationTransform = (transformStr: string): string => {
    const hasRotate = /rotate\([\s\S]*?\)/.test(transformStr);
    if (hasRotate) {
      return transformStr.replace(/rotate\([\s\S]*?\)/, 'rotate(0deg)');
    } else if (transformStr === 'none') {
      return 'rotate(0deg)';
    } else {
      return `${transformStr} rotate(0deg)`;
    }
  };

  const getPushedTransform = (baseTransform: string, offsetX: number): string => {
    const translateRegex = /translate\(([-0-9.]+)px\)/;
    const match = baseTransform.match(translateRegex);
    if (match) {
      const currentX = parseFloat(match[1]);
      const newX = currentX + offsetX;
      return baseTransform.replace(translateRegex, `translate(${newX}px)`);
    } else {
      return baseTransform === 'none' ? `translate(${offsetX}px)` : `${baseTransform} translate(${offsetX}px)`;
    }
  };

  const pushSiblings = (hoveredIdx: number) => {
    if (!enableHover || !containerRef.current) return;

    const q = gsap.utils.selector(containerRef);

    images.forEach((_, i) => {
      const target = q(`.card-${i}`);
      gsap.killTweensOf(target);

      const baseTransform = scaledTransforms[i] || 'none';

      if (i === hoveredIdx) {
        const noRotationTransform = getNoRotationTransform(baseTransform);
        gsap.to(target, {
          transform: noRotationTransform,
          duration: 0.4,
          ease: 'back.out(1.4)',
          overwrite: 'auto',
        });
      } else {
        const offsetX = i < hoveredIdx ? -scaledHover : scaledHover;
        const pushedTransform = getPushedTransform(baseTransform, offsetX);

        const distance = Math.abs(hoveredIdx - i);
        const delay = distance * 0.05;

        gsap.to(target, {
          transform: pushedTransform,
          duration: 0.4,
          ease: 'back.out(1.4)',
          delay,
          overwrite: 'auto',
        });
      }
    });
  };

  const resetSiblings = () => {
    if (!enableHover || !containerRef.current) return;

    const q = gsap.utils.selector(containerRef);

    images.forEach((_, i) => {
      const target = q(`.card-${i}`);
      gsap.killTweensOf(target);
      const baseTransform = scaledTransforms[i] || 'none';
      gsap.to(target, {
        transform: baseTransform,
        duration: 0.4,
        ease: 'back.out(1.4)',
        overwrite: 'auto',
      });
    });
  };

  return (
    <div
      className={`bounceCardsContainer ${className}`}
      ref={containerRef}
      style={{
        position: 'relative',
        width: cw,
        height: ch,
        ['--bc-card-size' as string]: `${cardSize}px`,
      }}
    >
      {images.map((src, idx) => (
        <div
          key={idx}
          className={`card card-${idx}`}
          style={{
            transform: scaledTransforms[idx] ?? 'none',
          }}
          onMouseEnter={() => pushSiblings(idx)}
          onMouseLeave={resetSiblings}
        >
          <img className="image" src={src} alt={captions?.[idx] || `card-${idx}`} />
          {captions?.[idx] ? (
            <span className="bc-caption" aria-hidden="true">{captions[idx]}</span>
          ) : null}
        </div>
      ))}
    </div>
  );
}
