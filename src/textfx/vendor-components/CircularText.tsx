// 1:1 port from reactbits.dev - CircularText
// Original: https://github.com/DavidHDev/react-bits/blob/main/src/content/TextAnimations/CircularText/CircularText.jsx

import { useEffect, useMemo } from 'react';
import { motion, useAnimation, useMotionValue } from 'motion/react';

const getRotationTransition = (duration: number, from: number, loop = true) => ({
  from,
  to: from + 360,
  ease: 'linear' as const,
  duration,
  type: 'tween' as const,
  repeat: loop ? Infinity : 0,
});

const getTransition = (duration: number, from: number) => ({
  rotate: getRotationTransition(duration, from),
  scale: {
    type: 'spring' as const,
    damping: 20,
    stiffness: 300,
  },
});

interface CircularTextProps {
  text?: string;
  spinDuration?: number;
  onHover?: 'speedUp' | 'slowDown' | 'pause' | 'goBonkers' | null;
  className?: string;
  diameter?: number;
}

const CircularText: React.FC<CircularTextProps> = ({
  text = 'CIRCULAR·TEXT·EFFECT·',
  spinDuration = 20,
  onHover = 'speedUp',
  className = '',
  diameter = 200,
}) => {
  const letters = Array.from(text);
  const controls = useAnimation();
  const rotation = useMotionValue(0);
  const styleId = useMemo(() => `bz-ct-${Math.random().toString(36).slice(2, 8)}`, []);

  useEffect(() => {
    const start = rotation.get();
    controls.start({
      rotate: start + 360,
      scale: 1,
      transition: getTransition(spinDuration, start),
    });
  }, [spinDuration, text, onHover, controls, rotation]);

  const handleHoverStart = () => {
    const start = rotation.get();
    if (!onHover) return;

    let transitionConfig: any;
    let scaleVal = 1;

    switch (onHover) {
      case 'slowDown':
        transitionConfig = getTransition(spinDuration * 2, start);
        break;
      case 'speedUp':
        transitionConfig = getTransition(spinDuration / 4, start);
        break;
      case 'pause':
        transitionConfig = {
          rotate: { type: 'spring', damping: 20, stiffness: 300 },
          scale: { type: 'spring', damping: 20, stiffness: 300 },
        };
        scaleVal = 1;
        break;
      case 'goBonkers':
        transitionConfig = getTransition(spinDuration / 20, start);
        scaleVal = 0.8;
        break;
      default:
        transitionConfig = getTransition(spinDuration, start);
    }

    controls.start({
      rotate: start + 360,
      scale: scaleVal,
      transition: transitionConfig,
    });
  };

  const handleHoverEnd = () => {
    const start = rotation.get();
    controls.start({
      rotate: start + 360,
      scale: 1,
      transition: getTransition(spinDuration, start),
    });
  };

  return (
    <>
      <style>{`
        .${styleId} {
          margin: 0 auto;
          border-radius: 50%;
          width: ${diameter}px;
          position: relative;
          height: ${diameter}px;
          font-weight: 900;
          color: #fff;
          text-align: center;
          cursor: pointer;
          transform-origin: 50% 50%;
          -webkit-transform-origin: 50% 50%;
        }
        .${styleId} span {
          position: absolute;
          display: inline-block;
          left: 0;
          right: 0;
          top: 0;
          bottom: 0;
          font-size: ${Math.max(Math.round(diameter * 0.12), 12)}px;
          transition: all 0.5s cubic-bezier(0, 0, 0, 1);
        }
      `}</style>
      <motion.div
        className={`${styleId} ${className}`}
        style={{ rotate: rotation }}
        initial={{ rotate: 0 }}
        animate={controls}
        onMouseEnter={handleHoverStart}
        onMouseLeave={handleHoverEnd}
      >
        {letters.map((letter, i) => {
          const rotationDeg = (360 / letters.length) * i;
          const factor = Math.PI / letters.length;
          const x = factor * i;
          const y = factor * i;
          const transform = `rotateZ(${rotationDeg}deg) translate3d(${x}px, ${y}px, 0)`;

          return (
            <span key={i} style={{ transform, WebkitTransform: transform }}>
              {letter}
            </span>
          );
        })}
      </motion.div>
    </>
  );
};

export default CircularText;
