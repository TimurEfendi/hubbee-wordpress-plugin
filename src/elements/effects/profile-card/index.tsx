import React from 'react';
import { createRoot, type Root } from 'react-dom/client';
import ProfileCard from '@hubbee-saas/components/ProfileCard';
import { mapProfileCard } from '@hubbee-shared/elements/section-to-vendor';
import { registerElementChunk } from '../../_chunk/register';

interface ProfileCardChunkProps {
  avatarUrl?: string;
  miniAvatarUrl?: string;
  iconUrl?: string;
  grainUrl?: string;
  name?: string;
  title?: string;
  handle?: string;
  status?: string;
  contactText?: string;
  innerGradient?: string;
  behindGlowEnabled?: boolean;
  behindGlowColor?: string;
  behindGlowSize?: string;
  enableTilt?: boolean;
  enableMobileTilt?: boolean;
  mobileTiltSensitivity?: number;
  showUserInfo?: boolean;
  cardBackground?: { type?: string; color?: string; gradientColors?: [string, string]; gradientAngle?: number };
}

const bgStyle = (bg?: ProfileCardChunkProps['cardBackground']): React.CSSProperties => {
  if (!bg || bg.type === 'none' || !bg.type) return {};
  if (bg.type === 'solid' && bg.color) return { background: bg.color };
  if (bg.type === 'gradient' && bg.gradientColors) {
    return { background: `linear-gradient(${bg.gradientAngle ?? 135}deg, ${bg.gradientColors[0]}, ${bg.gradientColors[1]})` };
  }
  return {};
};

registerElementChunk('profile-card', (container, rawConfig) => {
  const root: Root = createRoot(container);
  const render = (cfg: Record<string, unknown>) => {
    const props = mapProfileCard(cfg) as ProfileCardChunkProps;
    // ProfileCard's holographic effect IS the visual identity; cardBackground
    // would either clash with the holographic gradient or break the
    // mix-blend-mode chain on `.pc-avatar-content`. Intentional no-op.
    const bgCss = bgStyle(props.cardBackground).background as string | undefined;
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const PC = ProfileCard as React.ComponentType<any>;
    root.render(
      React.createElement(
        'div',
        // Flex-centre the card in the mountpoint's .bz-el-container (was a bare
        // inline-block → top-left). Mirrors decay-card/pixel-card.
        { style: { width: '100%', height: '100%', display: 'flex', alignItems: 'center', justifyContent: 'center' } },
        React.createElement(
          'div',
          { style: { borderRadius: '20px', display: 'inline-block', ...(bgCss ? { ['--hb-card-bg' as string]: bgCss } : {}) } },
          React.createElement(PC, props),
        ),
      ),
    );
  };
  render(rawConfig);
  return {
    update: (cfg: Record<string, unknown>) => render(cfg),
    unmount: () => root.unmount(),
  };
});
