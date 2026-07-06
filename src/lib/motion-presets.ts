/**
 * Motion Presets System
 *
 * Provides consistent animation presets for components.
 * Uses Framer Motion compatible values.
 */

import { useRef, useEffect, useState } from 'react';

// ============================================
// Types
// ============================================

export type MotionPreset = 'none' | 'subtle' | 'smooth' | 'punchy';
export type MotionTrigger = 'onLoad' | 'onScrollIntoView' | 'onHover' | 'onFocus';

export interface MotionConfig {
  duration: number;
  ease: string | number[];
  scale?: number;
  y?: number;
  opacity?: {
    from: number;
    to: number;
  };
}

export interface MotionVariants {
  initial: Record<string, unknown>;
  animate: Record<string, unknown>;
  whileHover?: Record<string, unknown>;
  whileFocus?: Record<string, unknown>;
  whileTap?: Record<string, unknown>;
}

// ============================================
// Preset Definitions
// ============================================

export const MOTION_PRESETS: Record<MotionPreset, MotionConfig> = {
  none: {
    duration: 0,
    ease: 'linear',
  },
  subtle: {
    duration: 0.3,
    ease: 'easeOut',
    scale: 1.01,
    y: 8,
    opacity: { from: 0.8, to: 1 },
  },
  smooth: {
    duration: 0.5,
    ease: [0.25, 0.1, 0.25, 1], // cubic-bezier
    scale: 1.02,
    y: 16,
    opacity: { from: 0, to: 1 },
  },
  punchy: {
    duration: 0.25,
    ease: [0.68, -0.55, 0.27, 1.55], // overshoot
    scale: 1.05,
    y: 24,
    opacity: { from: 0, to: 1 },
  },
};

// ============================================
// Variant Generators
// ============================================

export function getEntranceVariants(preset: MotionPreset): MotionVariants {
  const config = MOTION_PRESETS[preset];

  if (preset === 'none') {
    return {
      initial: {},
      animate: {},
    };
  }

  return {
    initial: {
      opacity: config.opacity?.from ?? 0,
      y: config.y ?? 0,
    },
    animate: {
      opacity: config.opacity?.to ?? 1,
      y: 0,
      transition: {
        duration: config.duration,
        ease: config.ease,
      },
    },
  };
}

export function getHoverVariants(preset: MotionPreset): MotionVariants {
  const config = MOTION_PRESETS[preset];

  if (preset === 'none') {
    return {
      initial: {},
      animate: {},
    };
  }

  return {
    initial: {},
    animate: {},
    whileHover: {
      scale: config.scale,
      transition: {
        duration: config.duration * 0.5,
        ease: config.ease,
      },
    },
    whileTap: {
      scale: (config.scale ?? 1) * 0.98,
      transition: {
        duration: 0.1,
      },
    },
  };
}

export function getFocusVariants(preset: MotionPreset): MotionVariants {
  const config = MOTION_PRESETS[preset];

  if (preset === 'none') {
    return {
      initial: {},
      animate: {},
    };
  }

  return {
    initial: {},
    animate: {},
    whileFocus: {
      scale: 1 + (((config.scale ?? 1) - 1) * 0.5),
      boxShadow: '0 0 0 3px var(--bz-primary, #3b82f6)',
      transition: {
        duration: config.duration * 0.5,
        ease: config.ease,
      },
    },
  };
}

// ============================================
// Hooks
// ============================================

interface UseMotionPresetOptions {
  preset: MotionPreset;
  trigger: MotionTrigger;
  threshold?: number; // for scroll trigger
  once?: boolean; // only animate once
}

interface UseMotionPresetResult {
  ref: React.RefObject<HTMLElement>;
  shouldAnimate: boolean;
  variants: MotionVariants;
  isInView: boolean;
}

export function useMotionPreset({
  preset,
  trigger,
  threshold = 0.2,
  once = true,
}: UseMotionPresetOptions): UseMotionPresetResult {
  const ref = useRef<HTMLElement>(null);
  const [isInView, setIsInView] = useState(trigger === 'onLoad');
  const [hasAnimated, setHasAnimated] = useState(false);

  // Get appropriate variants
  const variants = (() => {
    switch (trigger) {
      case 'onHover':
        return getHoverVariants(preset);
      case 'onFocus':
        return getFocusVariants(preset);
      default:
        return getEntranceVariants(preset);
    }
  })();

  // IntersectionObserver for scroll trigger
  useEffect(() => {
    if (trigger !== 'onScrollIntoView' || !ref.current) return;

    const observer = new IntersectionObserver(
      ([entry]) => {
        if (entry.isIntersecting) {
          setIsInView(true);
          setHasAnimated(true);
          if (once) {
            observer.disconnect();
          }
        } else if (!once) {
          setIsInView(false);
        }
      },
      { threshold }
    );

    observer.observe(ref.current);

    return () => observer.disconnect();
  }, [trigger, threshold, once]);

  // Determine if animation should play
  const shouldAnimate = (() => {
    if (preset === 'none') return false;
    if (once && hasAnimated && trigger === 'onScrollIntoView') return true;

    switch (trigger) {
      case 'onLoad':
        return true;
      case 'onScrollIntoView':
        return isInView;
      case 'onHover':
      case 'onFocus':
        return true; // These are handled by Framer Motion's whileHover/whileFocus
      default:
        return false;
    }
  })();

  return {
    ref: ref as React.RefObject<HTMLElement>,
    shouldAnimate,
    variants,
    isInView,
  };
}

// ============================================
// Stagger Children Helper
// ============================================

export function getStaggerChildren(
  preset: MotionPreset,
  count: number,
  staggerDelay = 0.05
): {
  container: MotionVariants;
  child: MotionVariants;
} {
  const config = MOTION_PRESETS[preset];

  if (preset === 'none') {
    return {
      container: { initial: {}, animate: {} },
      child: { initial: {}, animate: {} },
    };
  }

  return {
    container: {
      initial: {},
      animate: {
        transition: {
          staggerChildren: staggerDelay,
          delayChildren: 0.1,
        },
      },
    },
    child: {
      initial: {
        opacity: 0,
        y: config.y ?? 16,
      },
      animate: {
        opacity: 1,
        y: 0,
        transition: {
          duration: config.duration,
          ease: config.ease,
        },
      },
    },
  };
}

// ============================================
// CSS Custom Properties for Non-JS Fallback
// ============================================

export function getMotionCSSVars(preset: MotionPreset): Record<string, string> {
  const config = MOTION_PRESETS[preset];

  return {
    '--bz-motion-duration': `${config.duration}s`,
    '--bz-motion-ease': Array.isArray(config.ease)
      ? `cubic-bezier(${config.ease.join(', ')})`
      : config.ease,
    '--bz-motion-scale': String(config.scale ?? 1),
    '--bz-motion-y': `${config.y ?? 0}px`,
  };
}
