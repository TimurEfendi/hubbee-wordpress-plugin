// @ts-nocheck
/**
 * GridMotion Background Effect
 * Source: https://github.com/DavidHDev/react-bits (MIT License)
 * Original: src/content/Backgrounds/GridMotion/GridMotion.jsx
 *
 * Kept 1:1 with the original, only typed for TypeScript.
 * CSS inlined from GridMotion.css.
 * Dependencies: gsap
 */

import { useEffect, useRef } from 'react';
import { gsap } from 'gsap';

interface GridMotionProps {
  items?: (string | React.ReactNode)[];
  gradientColor?: string;
}

const gridMotionStyles = `
.gm-noscroll { height:100%; width:100%; overflow:hidden }
.gm-intro { width:100%; height:100vh; overflow:hidden; position:relative; display:flex; align-items:center; justify-content:center }
.gm-intro::after { content:''; position:absolute; top:0; left:0; width:100%; height:100%; background-size:250px; pointer-events:none; z-index:4 }
.gm-container { gap:1rem; flex:none; position:relative; width:150vw; height:150vh; display:grid; grid-template-rows:repeat(4,1fr); grid-template-columns:100%; transform:rotate(-15deg); transform-origin:center center; z-index:2 }
.gm-row { display:grid; gap:1rem; grid-template-columns:repeat(7,1fr); will-change:transform,filter }
.gm-row__item { position:relative }
.gm-row__item-inner { position:relative; width:100%; height:100%; overflow:hidden; border-radius:10px; background-color:#111; display:flex; align-items:center; justify-content:center; color:white; font-size:1.5rem }
.gm-row__item-img { width:100%; height:100%; background-size:cover; background-position:50% 50%; position:absolute; top:0; left:0 }
.gm-row__item-content { padding:1rem; text-align:center; z-index:1 }
.gm-fullview { position:relative; width:100%; height:100%; top:0; left:0; pointer-events:none }
.gm-fullview .gm-row__item-inner { border-radius:0px }
`;

const GridMotion = ({ items = [], gradientColor = 'black' }: GridMotionProps) => {
  const gridRef = useRef<HTMLDivElement>(null);
  const rowRefs = useRef<(HTMLDivElement | null)[]>([]);
  const mouseXRef = useRef(typeof window !== 'undefined' ? window.innerWidth / 2 : 0);
  const styleInjected = useRef(false);

  const totalItems = 28;
  const defaultItems = Array.from({ length: totalItems }, (_, index) => `Item ${index + 1}`);
  const combinedItems = items.length > 0 ? items.slice(0, totalItems) : defaultItems;

  useEffect(() => {
    if (!styleInjected.current) {
      const style = document.createElement('style');
      style.textContent = gridMotionStyles;
      document.head.appendChild(style);
      styleInjected.current = true;
    }
  }, []);

  useEffect(() => {
    gsap.ticker.lagSmoothing(0);

    const handleMouseMove = (e: MouseEvent) => { mouseXRef.current = e.clientX; };

    const updateMotion = () => {
      const maxMoveAmount = 300;
      const baseDuration = 0.8;
      const inertiaFactors = [0.6, 0.4, 0.3, 0.2];

      rowRefs.current.forEach((row, index) => {
        if (row) {
          const direction = index % 2 === 0 ? 1 : -1;
          const moveAmount = ((mouseXRef.current / window.innerWidth) * maxMoveAmount - maxMoveAmount / 2) * direction;
          gsap.to(row, { x: moveAmount, duration: baseDuration + inertiaFactors[index % inertiaFactors.length], ease: 'power3.out', overwrite: 'auto' });
        }
      });
    };

    const removeAnimationLoop = gsap.ticker.add(updateMotion);
    window.addEventListener('mousemove', handleMouseMove);

    return () => {
      window.removeEventListener('mousemove', handleMouseMove);
      removeAnimationLoop();
    };
  }, []);

  return (
    <div className="gm-noscroll" ref={gridRef}>
      <section className="gm-intro" style={{ background: `radial-gradient(circle, ${gradientColor} 0%, transparent 100%)` }}>
        <div className="gm-container">
          {[...Array(4)].map((_, rowIndex) => (
            <div key={rowIndex} className="gm-row" ref={el => (rowRefs.current[rowIndex] = el)}>
              {[...Array(7)].map((_, itemIndex) => {
                const content = combinedItems[rowIndex * 7 + itemIndex];
                return (
                  <div key={itemIndex} className="gm-row__item">
                    <div className="gm-row__item-inner" style={{ backgroundColor: '#111' }}>
                      {typeof content === 'string' && content.startsWith('http') ? (
                        <div className="gm-row__item-img" style={{ backgroundImage: `url(${content})` }}></div>
                      ) : (
                        <div className="gm-row__item-content">{content}</div>
                      )}
                    </div>
                  </div>
                );
              })}
            </div>
          ))}
        </div>
        <div className="gm-fullview"></div>
      </section>
    </div>
  );
};

export default GridMotion;
