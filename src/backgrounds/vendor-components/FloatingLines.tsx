/**
 * FloatingLines Background Effect
 * Source: https://github.com/DavidHDev/react-bits (MIT License)
 * Original: src/content/Backgrounds/FloatingLines/FloatingLines.jsx
 *
 * Kept 1:1 with the original, only typed for TypeScript.
 * CSS inlined.
 * Dependencies: three
 */

import { useEffect, useRef } from 'react';
import {
  Clock, Mesh, OrthographicCamera, PlaneGeometry, Scene,
  ShaderMaterial, Vector2, Vector3, WebGLRenderer
} from 'three';

interface FloatingLinesProps {
  linesGradient?: string[];
  enabledWaves?: string[];
  lineCount?: number | number[];
  lineDistance?: number | number[];
  topWavePosition?: { x?: number; y?: number; rotate?: number };
  middleWavePosition?: { x?: number; y?: number; rotate?: number };
  bottomWavePosition?: { x?: number; y?: number; rotate?: number };
  animationSpeed?: number;
  interactive?: boolean;
  bendRadius?: number;
  bendStrength?: number;
  mouseDamping?: number;
  parallax?: boolean;
  parallaxStrength?: number;
  mixBlendMode?: string;
}

const vertexShader = `
precision highp float;
void main() {
  gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}
`;

const fragmentShader = `
precision highp float;

uniform float iTime;
uniform vec3  iResolution;
uniform float animationSpeed;

uniform bool enableTop;
uniform bool enableMiddle;
uniform bool enableBottom;

uniform int topLineCount;
uniform int middleLineCount;
uniform int bottomLineCount;

uniform float topLineDistance;
uniform float middleLineDistance;
uniform float bottomLineDistance;

uniform vec3 topWavePosition;
uniform vec3 middleWavePosition;
uniform vec3 bottomWavePosition;

uniform vec2 iMouse;
uniform bool interactive;
uniform float bendRadius;
uniform float bendStrength;
uniform float bendInfluence;

uniform bool parallax;
uniform float parallaxStrength;
uniform vec2 parallaxOffset;

uniform vec3 lineGradient[8];
uniform int lineGradientCount;

const vec3 BLACK = vec3(0.0);
const vec3 PINK  = vec3(233.0, 71.0, 245.0) / 255.0;
const vec3 BLUE  = vec3(47.0,  75.0, 162.0) / 255.0;

mat2 rotate(float r) {
  return mat2(cos(r), sin(r), -sin(r), cos(r));
}

vec3 background_color(vec2 uv) {
  vec3 col = vec3(0.0);
  float y = sin(uv.x - 0.2) * 0.3 - 0.1;
  float m = uv.y - y;
  col += mix(BLUE, BLACK, smoothstep(0.0, 1.0, abs(m)));
  col += mix(PINK, BLACK, smoothstep(0.0, 1.0, abs(m - 0.8)));
  return col * 0.5;
}

vec3 getLineColor(float t, vec3 baseColor) {
  if (lineGradientCount <= 0) { return baseColor; }
  vec3 gradientColor;
  if (lineGradientCount == 1) {
    gradientColor = lineGradient[0];
  } else {
    float clampedT = clamp(t, 0.0, 0.9999);
    float scaled = clampedT * float(lineGradientCount - 1);
    int idx = int(floor(scaled));
    float f = fract(scaled);
    int idx2 = min(idx + 1, lineGradientCount - 1);
    vec3 c1 = lineGradient[idx];
    vec3 c2 = lineGradient[idx2];
    gradientColor = mix(c1, c2, f);
  }
  return gradientColor * 0.5;
}

float wave(vec2 uv, float offset, vec2 screenUv, vec2 mouseUv, bool shouldBend) {
  float time = iTime * animationSpeed;
  float x_offset   = offset;
  float x_movement = time * 0.1;
  float amp        = sin(offset + time * 0.2) * 0.3;
  float y          = sin(uv.x + x_offset + x_movement) * amp;
  if (shouldBend) {
    vec2 d = screenUv - mouseUv;
    float influence = exp(-dot(d, d) * bendRadius);
    float bendOffset = (mouseUv.y - screenUv.y) * influence * bendStrength * bendInfluence;
    y += bendOffset;
  }
  float m = uv.y - y;
  return 0.0175 / max(abs(m) + 0.01, 1e-3) + 0.01;
}

void mainImage(out vec4 fragColor, in vec2 fragCoord) {
  vec2 baseUv = (2.0 * fragCoord - iResolution.xy) / iResolution.y;
  baseUv.y *= -1.0;
  if (parallax) { baseUv += parallaxOffset; }
  vec3 col = vec3(0.0);
  vec3 b = lineGradientCount > 0 ? vec3(0.0) : background_color(baseUv);
  vec2 mouseUv = vec2(0.0);
  if (interactive) {
    mouseUv = (2.0 * iMouse - iResolution.xy) / iResolution.y;
    mouseUv.y *= -1.0;
  }
  if (enableBottom) {
    for (int i = 0; i < bottomLineCount; ++i) {
      float fi = float(i);
      float t = fi / max(float(bottomLineCount - 1), 1.0);
      vec3 lineCol = getLineColor(t, b);
      float angle = bottomWavePosition.z * log(length(baseUv) + 1.0);
      vec2 ruv = baseUv * rotate(angle);
      col += lineCol * wave(ruv + vec2(bottomLineDistance * fi + bottomWavePosition.x, bottomWavePosition.y), 1.5 + 0.2 * fi, baseUv, mouseUv, interactive) * 0.2;
    }
  }
  if (enableMiddle) {
    for (int i = 0; i < middleLineCount; ++i) {
      float fi = float(i);
      float t = fi / max(float(middleLineCount - 1), 1.0);
      vec3 lineCol = getLineColor(t, b);
      float angle = middleWavePosition.z * log(length(baseUv) + 1.0);
      vec2 ruv = baseUv * rotate(angle);
      col += lineCol * wave(ruv + vec2(middleLineDistance * fi + middleWavePosition.x, middleWavePosition.y), 2.0 + 0.15 * fi, baseUv, mouseUv, interactive);
    }
  }
  if (enableTop) {
    for (int i = 0; i < topLineCount; ++i) {
      float fi = float(i);
      float t = fi / max(float(topLineCount - 1), 1.0);
      vec3 lineCol = getLineColor(t, b);
      float angle = topWavePosition.z * log(length(baseUv) + 1.0);
      vec2 ruv = baseUv * rotate(angle);
      ruv.x *= -1.0;
      col += lineCol * wave(ruv + vec2(topLineDistance * fi + topWavePosition.x, topWavePosition.y), 1.0 + 0.2 * fi, baseUv, mouseUv, interactive) * 0.1;
    }
  }
  fragColor = vec4(col, 1.0);
}

void main() {
  vec4 color = vec4(0.0);
  mainImage(color, gl_FragCoord.xy);
  gl_FragColor = color;
}
`;

const MAX_GRADIENT_STOPS = 8;

function hexToVec3(hex: string) {
  let value = hex.trim();
  if (value.startsWith('#')) value = value.slice(1);
  let r = 255, g = 255, b = 255;
  if (value.length === 3) {
    r = parseInt(value[0] + value[0], 16);
    g = parseInt(value[1] + value[1], 16);
    b = parseInt(value[2] + value[2], 16);
  } else if (value.length === 6) {
    r = parseInt(value.slice(0, 2), 16);
    g = parseInt(value.slice(2, 4), 16);
    b = parseInt(value.slice(4, 6), 16);
  }
  return new Vector3(r / 255, g / 255, b / 255);
}

export default function FloatingLines(props: FloatingLinesProps) {
  const mixBlendMode = props.mixBlendMode ?? 'screen';

  const containerRef = useRef<HTMLDivElement>(null);
  const targetMouseRef = useRef(new Vector2(-1000, -1000));
  const currentMouseRef = useRef(new Vector2(-1000, -1000));
  const targetInfluenceRef = useRef(0);
  const currentInfluenceRef = useRef(0);
  const targetParallaxRef = useRef(new Vector2(0, 0));
  const currentParallaxRef = useRef(new Vector2(0, 0));

  // propsRef pattern: live config flows through every frame via the render
  // loop reading propsRef.current. The setup effect runs exactly once.
  const propsRef = useRef(props);
  propsRef.current = props;

  const getLineCount = (waves: string[], counts: number | number[], waveType: string) => {
    if (typeof counts === 'number') return counts;
    if (!waves.includes(waveType)) return 0;
    const index = waves.indexOf(waveType);
    return (counts as number[])[index] ?? 6;
  };

  const getLineDistance = (waves: string[], distances: number | number[], waveType: string) => {
    if (typeof distances === 'number') return distances;
    if (!waves.includes(waveType)) return 0.1;
    const index = waves.indexOf(waveType);
    return (distances as number[])[index] ?? 0.1;
  };

  useEffect(() => {
    const container = containerRef.current;
    if (!container) return;
    let active = true;

    const scene = new Scene();
    const camera = new OrthographicCamera(-1, 1, 1, -1, 0, 1);
    camera.position.z = 1;

    const renderer = new WebGLRenderer({ antialias: true, alpha: false });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.domElement.style.width = '100%';
    renderer.domElement.style.height = '100%';
    container.appendChild(renderer.domElement);

    const initial = propsRef.current;
    const initialWaves = initial.enabledWaves ?? ['top', 'middle', 'bottom'];
    const initialLineCount = initial.lineCount ?? [6];
    const initialLineDistance = initial.lineDistance ?? [5];

    const uniforms: any = {
      iTime: { value: 0 },
      iResolution: { value: new Vector3(1, 1, 1) },
      animationSpeed: { value: initial.animationSpeed ?? 1 },
      enableTop: { value: initialWaves.includes('top') },
      enableMiddle: { value: initialWaves.includes('middle') },
      enableBottom: { value: initialWaves.includes('bottom') },
      topLineCount: { value: initialWaves.includes('top') ? getLineCount(initialWaves, initialLineCount, 'top') : 0 },
      middleLineCount: { value: initialWaves.includes('middle') ? getLineCount(initialWaves, initialLineCount, 'middle') : 0 },
      bottomLineCount: { value: initialWaves.includes('bottom') ? getLineCount(initialWaves, initialLineCount, 'bottom') : 0 },
      topLineDistance: { value: (initialWaves.includes('top') ? getLineDistance(initialWaves, initialLineDistance, 'top') : 0.1) * 0.01 },
      middleLineDistance: { value: (initialWaves.includes('middle') ? getLineDistance(initialWaves, initialLineDistance, 'middle') : 0.1) * 0.01 },
      bottomLineDistance: { value: (initialWaves.includes('bottom') ? getLineDistance(initialWaves, initialLineDistance, 'bottom') : 0.1) * 0.01 },
      topWavePosition: { value: new Vector3(initial.topWavePosition?.x ?? 10.0, initial.topWavePosition?.y ?? 0.5, initial.topWavePosition?.rotate ?? -0.4) },
      middleWavePosition: { value: new Vector3(initial.middleWavePosition?.x ?? 5.0, initial.middleWavePosition?.y ?? 0.0, initial.middleWavePosition?.rotate ?? 0.2) },
      bottomWavePosition: { value: new Vector3(initial.bottomWavePosition?.x ?? 2.0, initial.bottomWavePosition?.y ?? -0.7, initial.bottomWavePosition?.rotate ?? 0.4) },
      iMouse: { value: new Vector2(-1000, -1000) },
      interactive: { value: initial.interactive ?? true },
      bendRadius: { value: initial.bendRadius ?? 5.0 },
      bendStrength: { value: initial.bendStrength ?? -0.5 },
      bendInfluence: { value: 0 },
      parallax: { value: initial.parallax ?? true },
      parallaxStrength: { value: initial.parallaxStrength ?? 0.2 },
      parallaxOffset: { value: new Vector2(0, 0) },
      lineGradient: { value: Array.from({ length: MAX_GRADIENT_STOPS }, () => new Vector3(1, 1, 1)) },
      lineGradientCount: { value: 0 }
    };

    const applyGradient = (gradient: string[] | undefined) => {
      if (gradient && gradient.length > 0) {
        const stops = gradient.slice(0, MAX_GRADIENT_STOPS);
        uniforms.lineGradientCount.value = stops.length;
        stops.forEach((hex: string, i: number) => {
          const color = hexToVec3(hex);
          uniforms.lineGradient.value[i].set(color.x, color.y, color.z);
        });
      } else {
        uniforms.lineGradientCount.value = 0;
      }
    };
    applyGradient(initial.linesGradient);
    let lastGradientKey = JSON.stringify(initial.linesGradient ?? null);

    const material = new ShaderMaterial({ uniforms, vertexShader, fragmentShader });
    const geometry = new PlaneGeometry(2, 2);
    const mesh = new Mesh(geometry, material);
    scene.add(mesh);

    const clock = new Clock();

    const setSize = () => {
      if (!active) return;
      const width = container.clientWidth || 1;
      const height = container.clientHeight || 1;
      renderer.setSize(width, height, false);
      const canvasWidth = renderer.domElement.width;
      const canvasHeight = renderer.domElement.height;
      uniforms.iResolution.value.set(canvasWidth, canvasHeight, 1);
    };
    setSize();

    const ro = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(() => { if (!active) return; setSize(); }) : null;
    if (ro) ro.observe(container);

    const handlePointerMove = (event: PointerEvent) => {
      const rect = renderer.domElement.getBoundingClientRect();
      const x = event.clientX - rect.left;
      const y = event.clientY - rect.top;
      const dpr = renderer.getPixelRatio();
      targetMouseRef.current.set(x * dpr, (rect.height - y) * dpr);
      targetInfluenceRef.current = 1.0;
      const live = propsRef.current;
      if (live.parallax !== false) {
        const centerX = rect.width / 2;
        const centerY = rect.height / 2;
        const offsetX = (x - centerX) / rect.width;
        const offsetY = -(y - centerY) / rect.height;
        const strength = live.parallaxStrength ?? 0.2;
        targetParallaxRef.current.set(offsetX * strength, offsetY * strength);
      }
    };

    const handlePointerLeave = () => { targetInfluenceRef.current = 0.0; };

    renderer.domElement.addEventListener('pointermove', handlePointerMove);
    renderer.domElement.addEventListener('pointerleave', handlePointerLeave);

    const onContextLost = (e: Event) => {
      e.preventDefault();
    };
    renderer.domElement.addEventListener('webglcontextlost', onContextLost);

    let raf = 0;
    const renderLoop = () => {
      if (!active) return;
      uniforms.iTime.value = clock.getElapsedTime();

      // ── live prop sync (propsRef pattern) ────────────────────────
      const p = propsRef.current;
      const waves = p.enabledWaves ?? ['top', 'middle', 'bottom'];
      const isInteractive = p.interactive !== false;
      const useParallax = p.parallax !== false;
      const damping = p.mouseDamping ?? 0.05;

      uniforms.animationSpeed.value = p.animationSpeed ?? 1;
      uniforms.enableTop.value = waves.includes('top');
      uniforms.enableMiddle.value = waves.includes('middle');
      uniforms.enableBottom.value = waves.includes('bottom');
      const lc = p.lineCount ?? [6];
      const ld = p.lineDistance ?? [5];
      uniforms.topLineCount.value = waves.includes('top') ? getLineCount(waves, lc, 'top') : 0;
      uniforms.middleLineCount.value = waves.includes('middle') ? getLineCount(waves, lc, 'middle') : 0;
      uniforms.bottomLineCount.value = waves.includes('bottom') ? getLineCount(waves, lc, 'bottom') : 0;
      uniforms.topLineDistance.value = (waves.includes('top') ? getLineDistance(waves, ld, 'top') : 0.1) * 0.01;
      uniforms.middleLineDistance.value = (waves.includes('middle') ? getLineDistance(waves, ld, 'middle') : 0.1) * 0.01;
      uniforms.bottomLineDistance.value = (waves.includes('bottom') ? getLineDistance(waves, ld, 'bottom') : 0.1) * 0.01;
      uniforms.topWavePosition.value.set(p.topWavePosition?.x ?? 10.0, p.topWavePosition?.y ?? 0.5, p.topWavePosition?.rotate ?? -0.4);
      uniforms.middleWavePosition.value.set(p.middleWavePosition?.x ?? 5.0, p.middleWavePosition?.y ?? 0.0, p.middleWavePosition?.rotate ?? 0.2);
      uniforms.bottomWavePosition.value.set(p.bottomWavePosition?.x ?? 2.0, p.bottomWavePosition?.y ?? -0.7, p.bottomWavePosition?.rotate ?? 0.4);
      uniforms.interactive.value = isInteractive;
      uniforms.bendRadius.value = p.bendRadius ?? 5.0;
      uniforms.bendStrength.value = p.bendStrength ?? -0.5;
      uniforms.parallax.value = useParallax;
      uniforms.parallaxStrength.value = p.parallaxStrength ?? 0.2;

      // Gradient is rebuilt only when its identity changes.
      const gradientKey = JSON.stringify(p.linesGradient ?? null);
      if (gradientKey !== lastGradientKey) {
        lastGradientKey = gradientKey;
        applyGradient(p.linesGradient);
      }

      if (isInteractive) {
        currentMouseRef.current.lerp(targetMouseRef.current, damping);
        uniforms.iMouse.value.copy(currentMouseRef.current);
        currentInfluenceRef.current += (targetInfluenceRef.current - currentInfluenceRef.current) * damping;
        uniforms.bendInfluence.value = currentInfluenceRef.current;
      } else {
        uniforms.bendInfluence.value = 0;
      }
      if (useParallax) {
        currentParallaxRef.current.lerp(targetParallaxRef.current, damping);
        uniforms.parallaxOffset.value.copy(currentParallaxRef.current);
      }
      renderer.render(scene, camera);
      raf = requestAnimationFrame(renderLoop);
    };
    renderLoop();

    return () => {
      active = false;
      cancelAnimationFrame(raf);
      if (ro) ro.disconnect();
      renderer.domElement.removeEventListener('pointermove', handlePointerMove);
      renderer.domElement.removeEventListener('pointerleave', handlePointerLeave);
      renderer.domElement.removeEventListener('webglcontextlost', onContextLost);
      geometry.dispose();
      material.dispose();
      renderer.dispose();
      renderer.forceContextLoss();
      if (renderer.domElement.parentElement) renderer.domElement.parentElement.removeChild(renderer.domElement);
    };
  }, []);

  return <div ref={containerRef} style={{ width: '100%', height: '100%', position: 'relative', overflow: 'hidden', mixBlendMode: mixBlendMode as any }} />;
}
