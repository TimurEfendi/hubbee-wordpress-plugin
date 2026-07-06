// @ts-nocheck
/** Source: react-bits by DavidHDev (MIT) — Hubbee fork.
 *  Greyscale + cursor spotlight reworked to be strictly card/image-scoped
 *  (no full-bleed backdrop-filter), so it works on any page background. */

import { useRef, useEffect, useCallback } from 'react';
import { gsap } from 'gsap';
import './ChromaGrid.css';

interface ChromaGridItem {
  image: string;
  title: string;
  subtitle?: string;
  handle?: string;
  borderColor?: string;
  gradient?: string;
  url?: string;
  location?: string;
}

interface ChromaGridProps {
  items?: ChromaGridItem[];
  className?: string;
  radius?: number;
  columns?: number;
  damping?: number;
  fadeOut?: number;
  ease?: string;
}

const demo: ChromaGridItem[] = [
  {
    image: 'https://i.pravatar.cc/300?img=8',
    title: 'Alex Rivera',
    subtitle: 'Full Stack Developer',
    handle: '@alexrivera',
    borderColor: '#4F46E5',
    gradient: 'linear-gradient(145deg, #4F46E5, #000)',
    url: 'https://github.com/'
  },
  {
    image: 'https://i.pravatar.cc/300?img=11',
    title: 'Jordan Chen',
    subtitle: 'DevOps Engineer',
    handle: '@jordanchen',
    borderColor: '#10B981',
    gradient: 'linear-gradient(210deg, #10B981, #000)',
    url: 'https://linkedin.com/in/'
  },
  {
    image: 'https://i.pravatar.cc/300?img=3',
    title: 'Morgan Blake',
    subtitle: 'UI/UX Designer',
    handle: '@morganblake',
    borderColor: '#F59E0B',
    gradient: 'linear-gradient(165deg, #F59E0B, #000)',
    url: 'https://dribbble.com/'
  }
];

export const ChromaGrid = ({
  items,
  className = '',
  radius = 300,
  columns = 3,
  damping = 0.45,
  fadeOut = 0.6,
  ease = 'power3.out'
}: ChromaGridProps) => {
  const rootRef = useRef<HTMLDivElement>(null);
  const wrappersRef = useRef<HTMLElement[]>([]);
  // Image-box rects (viewport coords) — the origin for each card's --cx/--cy.
  const rectsRef = useRef<DOMRect[]>([]);
  const damped = useRef({ x: -9999, y: -9999 });

  const data = items?.length ? items : demo;

  // Cache the image boxes; refreshed on layout changes so --cx/--cy stay aligned.
  const refreshRects = useCallback(() => {
    const el = rootRef.current;
    if (!el) return;
    const wraps = Array.from(el.querySelectorAll('.chroma-img-wrapper')) as HTMLElement[];
    wrappersRef.current = wraps;
    rectsRef.current = wraps.map((w) => (w.querySelector('img') ?? w).getBoundingClientRect());
  }, []);

  // Push the damped cursor into every card as a card-local coordinate. Each card
  // gets the cursor in its OWN image box, so the radial reveal spans neighbours
  // exactly like the original overlay — but can only ever paint inside an image.
  const paint = () => {
    const ws = wrappersRef.current;
    const rs = rectsRef.current;
    for (let i = 0; i < ws.length; i++) {
      const r = rs[i];
      if (!r) continue;
      ws[i].style.setProperty('--cx', `${damped.current.x - r.left}px`);
      ws[i].style.setProperty('--cy', `${damped.current.y - r.top}px`);
    }
  };

  useEffect(() => {
    refreshRects();
    const on = () => refreshRects();
    window.addEventListener('resize', on);
    window.addEventListener('scroll', on, true);
    return () => {
      window.removeEventListener('resize', on);
      window.removeEventListener('scroll', on, true);
    };
  }, [refreshRects, data.length]);

  const handleEnter = (e: React.PointerEvent) => {
    refreshRects();
    damped.current.x = e.clientX;
    damped.current.y = e.clientY;
    paint();
    gsap.to(rootRef.current, { '--r': `${radius}px`, duration: 0.2, overwrite: 'auto' });
  };

  const handleMove = (e: React.PointerEvent) => {
    if (!rectsRef.current.length) refreshRects();
    gsap.to(damped.current, {
      x: e.clientX,
      y: e.clientY,
      duration: damping,
      ease,
      onUpdate: paint,
      overwrite: true
    });
  };

  const handleLeave = () => {
    // Shrink the reveal radius to 0 → the greyscale base fades back in.
    gsap.to(rootRef.current, { '--r': '0px', duration: fadeOut, overwrite: 'auto' });
  };

  const handleCardClick = (url?: string) => {
    if (url) window.open(url, '_blank', 'noopener,noreferrer');
  };

  // Per-card border-glow tracking (the `.chroma-card::before` spotlight).
  const handleCardMove = (e: React.MouseEvent) => {
    const card = e.currentTarget as HTMLElement;
    const rect = card.getBoundingClientRect();
    card.style.setProperty('--mouse-x', `${e.clientX - rect.left}px`);
    card.style.setProperty('--mouse-y', `${e.clientY - rect.top}px`);
  };

  return (
    <div
      ref={rootRef}
      className={`chroma-grid ${className}`}
      style={{
        '--r': `${radius}px`,
        '--cols': columns
      } as any}
      onPointerEnter={handleEnter}
      onPointerMove={handleMove}
      onPointerLeave={handleLeave}
    >
      {data.map((c, i) => (
        <article
          key={i}
          className="chroma-card"
          onMouseMove={handleCardMove}
          onClick={() => handleCardClick(c.url)}
          style={{
            '--card-border': c.borderColor || 'transparent',
            '--card-gradient': c.gradient,
            '--img': c.image ? `url("${c.image}")` : 'none',
            cursor: c.url ? 'pointer' : 'default'
          } as any}
        >
          <div className="chroma-img-wrapper">
            <img src={c.image} alt={c.title} loading="lazy" />
          </div>
          <footer className="chroma-info">
            <h3 className="name">{c.title}</h3>
            {c.handle && <span className="handle">{c.handle}</span>}
            <p className="role">{c.subtitle}</p>
            {c.location && <span className="location">{c.location}</span>}
          </footer>
        </article>
      ))}
    </div>
  );
};

export default ChromaGrid;
