/**
 * FaultyTerminal Background Effect
 * Source: https://github.com/DavidHDev/react-bits (MIT License)
 * Original: src/content/Backgrounds/FaultyTerminal/FaultyTerminal.jsx
 *
 * Kept 1:1 with the original, only typed for TypeScript.
 * CSS inlined (was .faulty-terminal-container { width:100%; height:100%; position:relative; overflow:hidden })
 * Dependencies: ogl
 */

import { Renderer, Program, Mesh, Color, Triangle } from 'ogl';
import { useEffect, useRef, useCallback } from 'react';

interface FaultyTerminalProps {
  scale?: number;
  gridMul?: [number, number];
  digitSize?: number;
  timeScale?: number;
  pause?: boolean;
  scanlineIntensity?: number;
  glitchAmount?: number;
  flickerAmount?: number;
  noiseAmp?: number;
  chromaticAberration?: number;
  dither?: number | boolean;
  curvature?: number;
  tint?: string;
  mouseReact?: boolean;
  mouseStrength?: number;
  dpr?: number;
  pageLoadAnimation?: boolean;
  brightness?: number;
  className?: string;
  style?: React.CSSProperties;
  [key: string]: unknown;
}

const vertexShader = `
attribute vec2 position;
attribute vec2 uv;
varying vec2 vUv;
void main() {
  vUv = uv;
  gl_Position = vec4(position, 0.0, 1.0);
}
`;

const fragmentShader = `
precision mediump float;

varying vec2 vUv;

uniform float iTime;
uniform vec3  iResolution;
uniform float uScale;

uniform vec2  uGridMul;
uniform float uDigitSize;
uniform float uScanlineIntensity;
uniform float uGlitchAmount;
uniform float uFlickerAmount;
uniform float uNoiseAmp;
uniform float uChromaticAberration;
uniform float uDither;
uniform float uCurvature;
uniform vec3  uTint;
uniform vec2  uMouse;
uniform float uMouseStrength;
uniform float uUseMouse;
uniform float uPageLoadProgress;
uniform float uUsePageLoadAnimation;
uniform float uBrightness;

float time;

float hash21(vec2 p){
  p = fract(p * 234.56);
  p += dot(p, p + 34.56);
  return fract(p.x * p.y);
}

float noise(vec2 p)
{
  return sin(p.x * 10.0) * sin(p.y * (3.0 + sin(time * 0.090909))) + 0.2;
}

mat2 rotate(float angle)
{
  float c = cos(angle);
  float s = sin(angle);
  return mat2(c, -s, s, c);
}

float fbm(vec2 p)
{
  p *= 1.1;
  float f = 0.0;
  float amp = 0.5 * uNoiseAmp;

  mat2 modify0 = rotate(time * 0.02);
  f += amp * noise(p);
  p = modify0 * p * 2.0;
  amp *= 0.454545;

  mat2 modify1 = rotate(time * 0.02);
  f += amp * noise(p);
  p = modify1 * p * 2.0;
  amp *= 0.454545;

  mat2 modify2 = rotate(time * 0.08);
  f += amp * noise(p);

  return f;
}

float pattern(vec2 p, out vec2 q, out vec2 r) {
  vec2 offset1 = vec2(1.0);
  vec2 offset0 = vec2(0.0);
  mat2 rot01 = rotate(0.1 * time);
  mat2 rot1 = rotate(0.1);

  q = vec2(fbm(p + offset1), fbm(rot01 * p + offset1));
  r = vec2(fbm(rot1 * q + offset0), fbm(q + offset0));
  return fbm(p + r);
}

float digit(vec2 p){
    vec2 grid = uGridMul * 15.0;
    vec2 s = floor(p * grid) / grid;
    p = p * grid;
    vec2 q, r;
    float intensity = pattern(s * 0.1, q, r) * 1.3 - 0.03;

    if(uUseMouse > 0.5){
        vec2 mouseWorld = uMouse * uScale;
        float distToMouse = distance(s, mouseWorld);
        float mouseInfluence = exp(-distToMouse * 8.0) * uMouseStrength * 10.0;
        intensity += mouseInfluence;

        float ripple = sin(distToMouse * 20.0 - iTime * 5.0) * 0.1 * mouseInfluence;
        intensity += ripple;
    }

    if(uUsePageLoadAnimation > 0.5){
        float cellRandom = fract(sin(dot(s, vec2(12.9898, 78.233))) * 43758.5453);
        float cellDelay = cellRandom * 0.8;
        float cellProgress = clamp((uPageLoadProgress - cellDelay) / 0.2, 0.0, 1.0);

        float fadeAlpha = smoothstep(0.0, 1.0, cellProgress);
        intensity *= fadeAlpha;
    }

    p = fract(p);
    p *= uDigitSize;

    float px5 = p.x * 5.0;
    float py5 = (1.0 - p.y) * 5.0;
    float x = fract(px5);
    float y = fract(py5);

    float i = floor(py5) - 2.0;
    float j = floor(px5) - 2.0;
    float n = i * i + j * j;
    float f = n * 0.0625;

    float isOn = step(0.1, intensity - f);
    float brightness = isOn * (0.2 + y * 0.8) * (0.75 + x * 0.25);

    return step(0.0, p.x) * step(p.x, 1.0) * step(0.0, p.y) * step(p.y, 1.0) * brightness;
}

float onOff(float a, float b, float c)
{
  return step(c, sin(iTime + a * cos(iTime * b))) * uFlickerAmount;
}

float displace(vec2 look)
{
    float y = look.y - mod(iTime * 0.25, 1.0);
    float window = 1.0 / (1.0 + 50.0 * y * y);
    return sin(look.y * 20.0 + iTime) * 0.0125 * onOff(4.0, 2.0, 0.8) * (1.0 + cos(iTime * 60.0)) * window;
}

vec3 getColor(vec2 p){

    float bar = step(mod(p.y + time * 20.0, 1.0), 0.2) * 0.4 + 1.0;
    bar *= uScanlineIntensity;

    float displacement = displace(p);
    p.x += displacement;

    if (uGlitchAmount != 1.0) {
      float extra = displacement * (uGlitchAmount - 1.0);
      p.x += extra;
    }

    float middle = digit(p);

    const float off = 0.002;
    float sum = digit(p + vec2(-off, -off)) + digit(p + vec2(0.0, -off)) + digit(p + vec2(off, -off)) +
                digit(p + vec2(-off, 0.0)) + digit(p + vec2(0.0, 0.0)) + digit(p + vec2(off, 0.0)) +
                digit(p + vec2(-off, off)) + digit(p + vec2(0.0, off)) + digit(p + vec2(off, off));

    vec3 baseColor = vec3(0.9) * middle + sum * 0.1 * vec3(1.0) * bar;
    return baseColor;
}

vec2 barrel(vec2 uv){
  vec2 c = uv * 2.0 - 1.0;
  float r2 = dot(c, c);
  c *= 1.0 + uCurvature * r2;
  return c * 0.5 + 0.5;
}

void main() {
    time = iTime * 0.333333;
    vec2 uv = vUv;

    if(uCurvature != 0.0){
      uv = barrel(uv);
    }

    vec2 p = uv * uScale;
    vec3 col = getColor(p);

    if(uChromaticAberration != 0.0){
      vec2 ca = vec2(uChromaticAberration) / iResolution.xy;
      col.r = getColor(p + ca).r;
      col.b = getColor(p - ca).b;
    }

    col *= uTint;
    col *= uBrightness;

    if(uDither > 0.0){
      float rnd = hash21(gl_FragCoord.xy);
      col += (rnd - 0.5) * (uDither * 0.003922);
    }

    gl_FragColor = vec4(col, 1.0);
}
`;

function hexToRgb(hex: string) {
  let h = hex.replace('#', '').trim();
  if (h.length === 3)
    h = h.split('').map(c => c + c).join('');
  const num = parseInt(h, 16);
  return [((num >> 16) & 255) / 255, ((num >> 8) & 255) / 255, (num & 255) / 255];
}

export default function FaultyTerminal(props: FaultyTerminalProps) {
  // Setup-only / DOM-only props extracted; everything else flows through
  // propsRef.current and must NOT leak onto the DOM root via spread.
  const {
    dpr = Math.min(window.devicePixelRatio || 1, 2),
    className = '',
    style,
    pageLoadAnimation = true,
  } = props;
  // propsRef pattern: setup runs once on mount; the RAF loop reads live
  // values from propsRef.current every frame so config edits flow through
  // without rebuilding the WebGL context.
  const propsRef = useRef(props);
  propsRef.current = props;
  const containerRef = useRef<HTMLDivElement>(null);
  const programRef = useRef<any>(null);
  const rendererRef = useRef<any>(null);
  const mouseRef = useRef({ x: 0.5, y: 0.5 });
  const smoothMouseRef = useRef({ x: 0.5, y: 0.5 });
  const frozenTimeRef = useRef(0);
  const rafRef = useRef(0);
  const loadAnimationStartRef = useRef(0);
  const timeOffsetRef = useRef(Math.random() * 100);

  // tintVec / ditherValue are recomputed inside the RAF loop from propsRef.

  const handleMouseMove = useCallback((e: MouseEvent) => {
    const ctn = containerRef.current;
    if (!ctn) return;
    const rect = ctn.getBoundingClientRect();
    const x = (e.clientX - rect.left) / rect.width;
    const y = 1 - (e.clientY - rect.top) / rect.height;
    mouseRef.current = { x, y };
  }, []);

  useEffect(() => {
    const ctn = containerRef.current;
    if (!ctn) return;

    const renderer = new Renderer({ dpr });
    rendererRef.current = renderer;
    const gl = renderer.gl;
    gl.clearColor(0, 0, 0, 1);

    const geometry = new Triangle(gl);

    const initial = propsRef.current;
    const initialTint = hexToRgb(initial.tint ?? '#ffffff');
    const initialDither = typeof initial.dither === 'boolean' ? (initial.dither ? 1 : 0) : (initial.dither as number ?? 0);

    const program = new Program(gl, {
      vertex: vertexShader,
      fragment: fragmentShader,
      uniforms: {
        iTime: { value: 0 },
        iResolution: {
          value: new Color(gl.canvas.width, gl.canvas.height, gl.canvas.width / gl.canvas.height)
        },
        uScale: { value: initial.scale ?? 1 },
        uGridMul: { value: new Float32Array(initial.gridMul ?? [2, 1]) },
        uDigitSize: { value: initial.digitSize ?? 1.5 },
        uScanlineIntensity: { value: initial.scanlineIntensity ?? 0.3 },
        uGlitchAmount: { value: initial.glitchAmount ?? 1 },
        uFlickerAmount: { value: initial.flickerAmount ?? 1 },
        uNoiseAmp: { value: initial.noiseAmp ?? 0 },
        uChromaticAberration: { value: initial.chromaticAberration ?? 0 },
        uDither: { value: initialDither },
        uCurvature: { value: initial.curvature ?? 0.2 },
        uTint: { value: new Color(initialTint[0], initialTint[1], initialTint[2]) },
        uMouse: {
          value: new Float32Array([smoothMouseRef.current.x, smoothMouseRef.current.y])
        },
        uMouseStrength: { value: initial.mouseStrength ?? 0.2 },
        uUseMouse: { value: (initial.mouseReact ?? true) ? 1 : 0 },
        uPageLoadProgress: { value: pageLoadAnimation ? 0 : 1 },
        uUsePageLoadAnimation: { value: pageLoadAnimation ? 1 : 0 },
        uBrightness: { value: initial.brightness ?? 1 }
      }
    });
    programRef.current = program;

    const mesh = new Mesh(gl, { geometry, program });

    function resize() {
      if (!ctn || !renderer) return;
      renderer.setSize(ctn.offsetWidth, ctn.offsetHeight);
      program.uniforms.iResolution.value = new Color(
        gl.canvas.width,
        gl.canvas.height,
        gl.canvas.width / gl.canvas.height
      );
    }

    const resizeObserver = new ResizeObserver(() => resize());
    resizeObserver.observe(ctn);
    resize();

    let lastTintKey = initial.tint ?? '#ffffff';
    let lastDitherKey = JSON.stringify(initial.dither ?? 0);
    let lastGridMulKey = JSON.stringify(initial.gridMul ?? [2, 1]);

    const update = (t: number) => {
      rafRef.current = requestAnimationFrame(update);
      const live = propsRef.current;

      if (pageLoadAnimation && loadAnimationStartRef.current === 0) {
        loadAnimationStartRef.current = t;
      }

      if (!live.pause) {
        const elapsed = (t * 0.001 + timeOffsetRef.current) * (live.timeScale ?? 0.3);
        program.uniforms.iTime.value = elapsed;
        frozenTimeRef.current = elapsed;
      } else {
        program.uniforms.iTime.value = frozenTimeRef.current;
      }

      // Live shader-uniform updates from latest props.
      program.uniforms.uScale.value = live.scale ?? 1;
      program.uniforms.uDigitSize.value = live.digitSize ?? 1.5;
      program.uniforms.uScanlineIntensity.value = live.scanlineIntensity ?? 0.3;
      program.uniforms.uGlitchAmount.value = live.glitchAmount ?? 1;
      program.uniforms.uFlickerAmount.value = live.flickerAmount ?? 1;
      program.uniforms.uNoiseAmp.value = live.noiseAmp ?? 0;
      program.uniforms.uChromaticAberration.value = live.chromaticAberration ?? 0;
      program.uniforms.uCurvature.value = live.curvature ?? 0.2;
      program.uniforms.uMouseStrength.value = live.mouseStrength ?? 0.2;
      program.uniforms.uUseMouse.value = (live.mouseReact ?? true) ? 1 : 0;
      program.uniforms.uBrightness.value = live.brightness ?? 1;

      const tintKey = live.tint ?? '#ffffff';
      if (tintKey !== lastTintKey) {
        lastTintKey = tintKey;
        const rgb = hexToRgb(tintKey);
        program.uniforms.uTint.value.r = rgb[0];
        program.uniforms.uTint.value.g = rgb[1];
        program.uniforms.uTint.value.b = rgb[2];
      }
      const ditherKey = JSON.stringify(live.dither ?? 0);
      if (ditherKey !== lastDitherKey) {
        lastDitherKey = ditherKey;
        program.uniforms.uDither.value = typeof live.dither === 'boolean' ? (live.dither ? 1 : 0) : (live.dither as number ?? 0);
      }
      const gridKey = JSON.stringify(live.gridMul ?? [2, 1]);
      if (gridKey !== lastGridMulKey) {
        lastGridMulKey = gridKey;
        const gm = live.gridMul ?? [2, 1];
        program.uniforms.uGridMul.value[0] = gm[0];
        program.uniforms.uGridMul.value[1] = gm[1];
      }

      if (pageLoadAnimation && loadAnimationStartRef.current > 0) {
        const animationDuration = 2000;
        const animationElapsed = t - loadAnimationStartRef.current;
        const progress = Math.min(animationElapsed / animationDuration, 1);
        program.uniforms.uPageLoadProgress.value = progress;
      }

      if (live.mouseReact ?? true) {
        const dampingFactor = 0.08;
        const smoothMouse = smoothMouseRef.current;
        const mouse = mouseRef.current;
        smoothMouse.x += (mouse.x - smoothMouse.x) * dampingFactor;
        smoothMouse.y += (mouse.y - smoothMouse.y) * dampingFactor;

        const mouseUniform = program.uniforms.uMouse.value;
        mouseUniform[0] = smoothMouse.x;
        mouseUniform[1] = smoothMouse.y;
      }

      renderer.render({ scene: mesh });
    };
    rafRef.current = requestAnimationFrame(update);
    ctn.appendChild(gl.canvas);

    ctn.addEventListener('mousemove', handleMouseMove);
    const onContextLost = (e: Event) => { e.preventDefault(); };
    gl.canvas.addEventListener('webglcontextlost', onContextLost);

    return () => {
      cancelAnimationFrame(rafRef.current);
      resizeObserver.disconnect();
      ctn.removeEventListener('mousemove', handleMouseMove);
      gl.canvas.removeEventListener('webglcontextlost', onContextLost);
      if (gl.canvas.parentElement === ctn) ctn.removeChild(gl.canvas);
      gl.getExtension('WEBGL_lose_context')?.loseContext();
      loadAnimationStartRef.current = 0;
      timeOffsetRef.current = Math.random() * 100;
    };
  }, [dpr, pageLoadAnimation, handleMouseMove]);

  return <div ref={containerRef} className={className} style={{ width: '100%', height: '100%', position: 'relative', overflow: 'hidden', ...style }} />;
}
