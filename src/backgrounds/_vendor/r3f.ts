import { Canvas, extend, useFrame, useThree } from '@react-three/fiber';
import { Environment, Lightformer, PerspectiveCamera } from '@react-three/drei';
import { EffectComposer, wrapEffect } from '@react-three/postprocessing';

const HubbeeR3F = {
  fiber: { Canvas, extend, useFrame, useThree },
  drei: { Environment, Lightformer, PerspectiveCamera },
  postprocessing: { EffectComposer, wrapEffect },
};

if (typeof window !== 'undefined') {
  (window as unknown as { HubbeeR3F: typeof HubbeeR3F }).HubbeeR3F = HubbeeR3F;
}

export default HubbeeR3F;
