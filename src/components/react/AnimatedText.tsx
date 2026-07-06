/**
 * AnimatedText - Text Animations Component
 * Implements blur, gradient, shiny, and typewriter text effects
 */

import { useRef, useEffect, useState, createElement, type ElementType } from 'react';
import { motion, useInView } from 'motion/react';

export type TextAnimation = 'none' | 'blur' | 'gradient' | 'shiny' | 'type';

interface AnimatedTextProps {
  text: string;
  animation: TextAnimation;
  as?: 'h1' | 'h2' | 'h3' | 'h4' | 'p' | 'span';
  className?: string;
  style?: React.CSSProperties;
}

export function AnimatedText({
  text,
  animation,
  as: Tag = 'span',
  className = '',
  style
}: AnimatedTextProps) {
  if (animation === 'none' || !animation) {
    return createElement(Tag, { className, style }, text);
  }

  switch (animation) {
    case 'blur':
      return <BlurText text={text} Tag={Tag} className={className} style={style} />;
    case 'gradient':
      return <GradientText text={text} Tag={Tag} className={className} style={style} />;
    case 'shiny':
      return <ShinyText text={text} Tag={Tag} className={className} style={style} />;
    case 'type':
      return <TypewriterText text={text} Tag={Tag} className={className} style={style} />;
    default:
      return createElement(Tag, { className, style }, text);
  }
}

// ============================================
// Blur Text Animation
// ============================================

interface TextAnimationProps {
  text: string;
  Tag: ElementType;
  className?: string;
  style?: React.CSSProperties;
}

function BlurText({ text, Tag, className, style }: TextAnimationProps) {
  const ref = useRef(null);
  const isInView = useInView(ref, { once: true, margin: '-50px' });
  const words = text.split(' ');

  return (
    <Tag ref={ref} className={className} style={style}>
      {words.map((word, i) => (
        <motion.span
          key={i}
          initial={{ filter: 'blur(10px)', opacity: 0, y: 20 }}
          animate={isInView ? { filter: 'blur(0px)', opacity: 1, y: 0 } : {}}
          transition={{ duration: 0.5, delay: i * 0.08 }}
          style={{ display: 'inline-block', marginRight: '0.25em' }}
        >
          {word}
        </motion.span>
      ))}
    </Tag>
  );
}

// ============================================
// Gradient Text Animation
// ============================================

function GradientText({ text, Tag, className, style }: TextAnimationProps) {
  const ref = useRef(null);
  const isInView = useInView(ref, { once: true, margin: '-50px' });

  return (
    <Tag ref={ref} className={className} style={style}>
      <motion.span
        initial={{ opacity: 0 }}
        animate={isInView ? { opacity: 1 } : {}}
        transition={{ duration: 0.5 }}
        className="bz-cf-gradient-text"
      >
        {text}
      </motion.span>
    </Tag>
  );
}

// ============================================
// Shiny Text Animation
// ============================================

function ShinyText({ text, Tag, className, style }: TextAnimationProps) {
  const ref = useRef(null);
  const isInView = useInView(ref, { once: true, margin: '-50px' });

  return (
    <Tag ref={ref} className={className} style={style}>
      <motion.span
        initial={{ opacity: 0 }}
        animate={isInView ? { opacity: 1 } : {}}
        transition={{ duration: 0.3 }}
        className="bz-cf-shiny-text"
      >
        {text}
      </motion.span>
    </Tag>
  );
}

// ============================================
// Typewriter Text Animation
// ============================================

function TypewriterText({ text, Tag, className, style }: TextAnimationProps) {
  const [displayText, setDisplayText] = useState('');
  const [showCursor, setShowCursor] = useState(true);
  const ref = useRef(null);
  const isInView = useInView(ref, { once: true, margin: '-50px' });
  const hasStarted = useRef(false);

  useEffect(() => {
    if (!isInView || hasStarted.current) return;
    hasStarted.current = true;

    let index = 0;
    const timer = setInterval(() => {
      if (index <= text.length) {
        setDisplayText(text.slice(0, index));
        index++;
      } else {
        clearInterval(timer);
      }
    }, 50);

    // Cursor blink
    const cursorTimer = setInterval(() => {
      setShowCursor(prev => !prev);
    }, 530);

    return () => {
      clearInterval(timer);
      clearInterval(cursorTimer);
    };
  }, [isInView, text]);

  return (
    <Tag ref={ref} className={className} style={style}>
      <span>{displayText}</span>
      <span className={`bz-cf-cursor ${showCursor ? 'bz-cf-cursor--visible' : ''}`}>|</span>
    </Tag>
  );
}

export default AnimatedText;
