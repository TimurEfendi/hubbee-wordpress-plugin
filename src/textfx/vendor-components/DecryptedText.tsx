import { useEffect, useState, useRef, useMemo, useCallback } from 'react';

interface DecryptedTextProps {
  text?: string;
  speed?: number;
  maxIterations?: number;
  sequential?: boolean;
  revealDirection?: 'start' | 'end' | 'center';
  useOriginalCharsOnly?: boolean;
  characters?: string;
  className?: string;
  parentClassName?: string;
  encryptedClassName?: string;
  animateOn?: 'hover' | 'click' | 'view';
  fontSize?: number;
}

const DecryptedText: React.FC<DecryptedTextProps> = ({
  text = 'Decrypted',
  speed = 50,
  maxIterations = 10,
  sequential = false,
  revealDirection = 'start',
  useOriginalCharsOnly = false,
  characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz!@#$%^&*()_+',
  className = '',
  parentClassName = '',
  encryptedClassName = '',
  animateOn = 'hover',
  fontSize: fontSizeProp,
}) => {
  const [displayText, setDisplayText] = useState(text);
  const [isAnimating, setIsAnimating] = useState(false);
  const [revealedIndices, setRevealedIndices] = useState<Set<number>>(new Set());
  const [hasAnimated, setHasAnimated] = useState(false);
  const [isDecrypted, setIsDecrypted] = useState(animateOn !== 'click');
  const containerRef = useRef<HTMLSpanElement>(null);

  const availableChars = useMemo(() => {
    return useOriginalCharsOnly
      ? Array.from(new Set(text.split(''))).filter(c => c !== ' ')
      : characters.split('');
  }, [useOriginalCharsOnly, text, characters]);

  const shuffleText = useCallback((originalText: string, currentRevealed: Set<number>) => {
    return originalText.split('').map((char, i) => {
      if (char === ' ') return ' ';
      if (currentRevealed.has(i)) return originalText[i];
      return availableChars[Math.floor(Math.random() * availableChars.length)];
    }).join('');
  }, [availableChars]);

  const getNextIndex = useCallback((revealedSet: Set<number>) => {
    const len = text.length;
    switch (revealDirection) {
      case 'end': return len - 1 - revealedSet.size;
      case 'center': {
        const mid = Math.floor(len / 2);
        const off = Math.floor(revealedSet.size / 2);
        const idx = revealedSet.size % 2 === 0 ? mid + off : mid - off - 1;
        if (idx >= 0 && idx < len && !revealedSet.has(idx)) return idx;
        for (let i = 0; i < len; i++) { if (!revealedSet.has(i)) return i; }
        return 0;
      }
      default: return revealedSet.size;
    }
  }, [text, revealDirection]);

  useEffect(() => {
    if (!isAnimating) return;
    let iteration = 0;
    const interval = setInterval(() => {
      setRevealedIndices(prev => {
        if (sequential) {
          if (prev.size < text.length) {
            const next = new Set(prev);
            next.add(getNextIndex(prev));
            setDisplayText(shuffleText(text, next));
            return next;
          }
          clearInterval(interval);
          setIsAnimating(false);
          setIsDecrypted(true);
          return prev;
        }
        setDisplayText(shuffleText(text, prev));
        iteration++;
        if (iteration >= maxIterations) {
          clearInterval(interval);
          setIsAnimating(false);
          setDisplayText(text);
          setIsDecrypted(true);
        }
        return prev;
      });
    }, speed);
    return () => clearInterval(interval);
  }, [isAnimating, text, speed, maxIterations, sequential, shuffleText, getNextIndex]);

  const triggerDecrypt = useCallback(() => {
    if (isAnimating) return;
    setRevealedIndices(new Set());
    setIsDecrypted(false);
    setIsAnimating(true);
  }, [isAnimating]);

  const resetToPlain = useCallback(() => {
    setIsAnimating(false);
    setRevealedIndices(new Set());
    setDisplayText(text);
    setIsDecrypted(true);
  }, [text]);

  useEffect(() => {
    if (animateOn !== 'view') return;
    const observer = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting && !hasAnimated) {
          triggerDecrypt();
          setHasAnimated(true);
        }
      });
    }, { threshold: 0.1 });
    if (containerRef.current) observer.observe(containerRef.current);
    return () => observer.disconnect();
  }, [animateOn, hasAnimated, triggerDecrypt]);

  useEffect(() => {
    if (animateOn === 'click') {
      setDisplayText(shuffleText(text, new Set()));
      setIsDecrypted(false);
    } else {
      setDisplayText(text);
      setIsDecrypted(true);
    }
  }, [animateOn, text, shuffleText]);

  const handlers = animateOn === 'hover'
    ? { onMouseEnter: triggerDecrypt, onMouseLeave: resetToPlain }
    : animateOn === 'click'
      ? { onClick: () => { if (!isDecrypted) triggerDecrypt(); } }
      : {};

  return (
    <span
      ref={containerRef}
      className={parentClassName}
      style={{ display: 'inline-block', whiteSpace: 'pre-wrap', fontSize: fontSizeProp ? `${fontSizeProp}px` : undefined, cursor: animateOn === 'click' ? 'pointer' : animateOn === 'hover' ? 'default' : undefined }}
      {...handlers}
    >
      <span style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clip: 'rect(0,0,0,0)' }}>{displayText}</span>
      <span aria-hidden="true">
        {displayText.split('').map((char, i) => {
          const revealed = revealedIndices.has(i) || (!isAnimating && isDecrypted);
          return <span key={i} className={revealed ? className : encryptedClassName} style={{ opacity: revealed ? 1 : 0.6 }}>{char}</span>;
        })}
      </span>
    </span>
  );
};

export default DecryptedText;
