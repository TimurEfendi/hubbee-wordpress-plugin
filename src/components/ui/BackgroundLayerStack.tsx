/**
 * BackgroundLayerStack - Multi-layer background system
 *
 * Renders multiple visual layers in correct z-order:
 * 1. Gradient/Mesh (base)
 * 2. Aurora/Waves animation (optional)
 * 3. Glow orbs (optional)
 * 4. Noise texture (optional)
 * 5. Glass panel (top)
 */

import React, { useMemo } from 'react';
import Aurora from '../react/Aurora';
import Waves from '../react/Waves';

// ============================================
// Types
// ============================================

export interface GlowOrb {
  x: string; // CSS position (e.g., '20%', '100px')
  y: string;
  size: string; // e.g., '200px', '300px'
  color: string; // hex color
  blur: number; // blur radius in px
}

export interface BackgroundLayer {
  type: 'glass' | 'gradient' | 'noise' | 'glow-orb' | 'aurora' | 'waves' | 'mesh';
  enabled: boolean;
  config: {
    // Glass
    blur?: number; // 4-20px
    opacity?: number; // 0-1
    tint?: string; // overlay color

    // Gradient
    colors?: string[]; // 2-4 colors
    angle?: number; // 0-360
    animated?: boolean;

    // Noise
    intensity?: number; // 0-0.3

    // Glow Orbs
    orbs?: GlowOrb[];

    // Mesh Gradient
    meshPoints?: Array<{ x: number; y: number; color: string }>;

    // Aurora/Waves specific
    colorStops?: string[];
    speed?: number;
  };
}

interface BackgroundLayerStackProps {
  layers: BackgroundLayer[];
  className?: string;
}

// ============================================
// Layer Components
// ============================================

function GlassLayer({ config }: { config: BackgroundLayer['config'] }) {
  const blur = config.blur ?? 12;
  const opacity = config.opacity ?? 0.5;
  const tint = config.tint ?? 'rgba(255, 255, 255, 0.1)';

  return (
    <div
      className="bz-bg-layer bz-bg-layer--glass"
      style={{
        backdropFilter: `blur(${blur}px)`,
        WebkitBackdropFilter: `blur(${blur}px)`,
        backgroundColor: tint,
        opacity,
      }}
    />
  );
}

function GradientLayer({ config }: { config: BackgroundLayer['config'] }) {
  const gradientStyle = useMemo(() => {
    const colors = config.colors ?? ['#3b82f6', '#8b5cf6'];
    const angle = config.angle ?? 135;
    const animated = config.animated ?? false;
    const gradient = `linear-gradient(${angle}deg, ${colors.join(', ')})`;
    return animated
      ? { backgroundImage: gradient, backgroundSize: '200% 200%' }
      : { backgroundImage: gradient };
  }, [config.colors, config.angle, config.animated]);

  const animated = config.animated ?? false;

  return (
    <div
      className={`bz-bg-layer bz-bg-layer--gradient${animated ? ' bz-bg-layer--animated' : ''}`}
      style={gradientStyle}
    />
  );
}

function NoiseLayer({ config }: { config: BackgroundLayer['config'] }) {
  const intensity = config.intensity ?? 0.15;

  return (
    <div
      className="bz-bg-layer bz-bg-layer--noise"
      style={{ opacity: intensity }}
    >
      <svg width="100%" height="100%" xmlns="http://www.w3.org/2000/svg">
        <defs>
          <filter id="bz-noise-filter">
            <feTurbulence
              type="fractalNoise"
              baseFrequency="0.8"
              numOctaves="4"
              stitchTiles="stitch"
            />
            <feColorMatrix type="saturate" values="0" />
          </filter>
        </defs>
        <rect width="100%" height="100%" filter="url(#bz-noise-filter)" />
      </svg>
    </div>
  );
}

function GlowOrbLayer({ config }: { config: BackgroundLayer['config'] }) {
  const orbs = config.orbs ?? [
    { x: '20%', y: '30%', size: '300px', color: '#3b82f6', blur: 100 },
    { x: '70%', y: '60%', size: '250px', color: '#8b5cf6', blur: 80 },
  ];

  return (
    <div className="bz-bg-layer bz-bg-layer--orbs">
      {orbs.map((orb, index) => (
        <div
          key={index}
          className="bz-glow-orb"
          style={{
            left: orb.x,
            top: orb.y,
            width: orb.size,
            height: orb.size,
            background: `radial-gradient(circle, ${orb.color} 0%, transparent 70%)`,
            filter: `blur(${orb.blur}px)`,
          }}
        />
      ))}
    </div>
  );
}

function MeshGradientLayer({ config }: { config: BackgroundLayer['config'] }) {
  // Generate CSS mesh gradient
  const meshGradient = useMemo(() => {
    const points = config.meshPoints ?? [
      { x: 20, y: 20, color: '#3b82f6' },
      { x: 80, y: 30, color: '#8b5cf6' },
      { x: 50, y: 80, color: '#06b6d4' },
    ];
    const gradients = points.map(
      (p) => `radial-gradient(circle at ${p.x}% ${p.y}%, ${p.color}40 0%, transparent 50%)`
    );
    return gradients.join(', ');
  }, [config.meshPoints]);

  return (
    <div
      className="bz-bg-layer bz-bg-layer--mesh"
      style={{ backgroundImage: meshGradient }}
    />
  );
}

function AuroraLayer({ config }: { config: BackgroundLayer['config'] }) {
  return (
    <div className="bz-bg-layer bz-bg-layer--aurora">
      <Aurora colorStops={config.colorStops} speed={config.speed} />
    </div>
  );
}

// eslint-disable-next-line @typescript-eslint/no-unused-vars
function WavesLayer({ config }: { config: BackgroundLayer['config'] }) {
  return (
    <div className="bz-bg-layer bz-bg-layer--waves">
      <Waves />
    </div>
  );
}

// ============================================
// Main Component
// ============================================

export default function BackgroundLayerStack({
  layers,
  className = '',
}: BackgroundLayerStackProps) {
  const enabledLayers = layers.filter((l) => l.enabled);

  if (enabledLayers.length === 0) {
    return null;
  }

  return (
    <div className={`bz-bg-stack ${className}`}>
      {enabledLayers.map((layer, index) => {
        const key = `${layer.type}-${index}`;

        switch (layer.type) {
          case 'gradient':
            return <GradientLayer key={key} config={layer.config} />;
          case 'mesh':
            return <MeshGradientLayer key={key} config={layer.config} />;
          case 'aurora':
            return <AuroraLayer key={key} config={layer.config} />;
          case 'waves':
            return <WavesLayer key={key} config={layer.config} />;
          case 'glow-orb':
            return <GlowOrbLayer key={key} config={layer.config} />;
          case 'noise':
            return <NoiseLayer key={key} config={layer.config} />;
          case 'glass':
            return <GlassLayer key={key} config={layer.config} />;
          default:
            return null;
        }
      })}
    </div>
  );
}
