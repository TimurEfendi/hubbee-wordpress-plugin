/**
 * Galaxy Background Effect
 * Source: https://github.com/DavidHDev/react-bits (MIT License)
 * Original: src/content/Backgrounds/Galaxy/Galaxy.jsx
 *
 * Kept 1:1 with the original, only typed for TypeScript.
 * CSS inlined.
 * Dependencies: ogl
 */

import { Renderer, Program, Mesh, Color, Triangle } from 'ogl';
import { useEffect, useRef } from 'react';

interface GalaxyProps {
  focal?: [number, number];
  rotation?: [number, number];
  starSpeed?: number;
  density?: number;
  hueShift?: number;
  disableAnimation?: boolean;
  speed?: number;
  mouseInteraction?: boolean;
  glowIntensity?: number;
  saturation?: number;
  mouseRepulsion?: boolean;
  repulsionStrength?: number;
  twinkleIntensity?: number;
  rotationSpeed?: number;
  autoCenterRepulsion?: number;
  transparent?: boolean;
  [key: string]: unknown;
}

const vertexShader = `
attribute vec2 uv;
attribute vec2 position;
varying vec2 vUv;
void main() {
  vUv = uv;
  gl_Position = vec4(position, 0, 1);
}
`;

const fragmentShader = `
precision highp float;

uniform float uTime;
uniform vec3 uResolution;
uniform vec2 uFocal;
uniform vec2 uRotation;
uniform float uStarSpeed;
uniform float uDensity;
uniform float uHueShift;
uniform float uSpeed;
uniform vec2 uMouse;
uniform float uGlowIntensity;
uniform float uSaturation;
uniform bool uMouseRepulsion;
uniform float uTwinkleIntensity;
uniform float uRotationSpeed;
uniform float uRepulsionStrength;
uniform float uMouseActiveFactor;
uniform float uAutoCenterRepulsion;
uniform bool uTransparent;

varying vec2 vUv;

#define NUM_LAYER 4.0
#define STAR_COLOR_CUTOFF 0.2
#define MAT45 mat2(0.7071, -0.7071, 0.7071, 0.7071)
#define PERIOD 3.0

float Hash21(vec2 p) {
  p = fract(p * vec2(123.34, 456.21));
  p += dot(p, p + 45.32);
  return fract(p.x * p.y);
}

float tri(float x) { return abs(fract(x) * 2.0 - 1.0); }
float tris(float x) { float t = fract(x); return 1.0 - smoothstep(0.0, 1.0, abs(2.0 * t - 1.0)); }
float trisn(float x) { float t = fract(x); return 2.0 * (1.0 - smoothstep(0.0, 1.0, abs(2.0 * t - 1.0))) - 1.0; }

vec3 hsv2rgb(vec3 c) {
  vec4 K = vec4(1.0, 2.0 / 3.0, 1.0 / 3.0, 3.0);
  vec3 p = abs(fract(c.xxx + K.xyz) * 6.0 - K.www);
  return c.z * mix(K.xxx, clamp(p - K.xxx, 0.0, 1.0), c.y);
}

float Star(vec2 uv, float flare) {
  float d = length(uv);
  float m = (0.05 * uGlowIntensity) / d;
  float rays = smoothstep(0.0, 1.0, 1.0 - abs(uv.x * uv.y * 1000.0));
  m += rays * flare * uGlowIntensity;
  uv *= MAT45;
  rays = smoothstep(0.0, 1.0, 1.0 - abs(uv.x * uv.y * 1000.0));
  m += rays * 0.3 * flare * uGlowIntensity;
  m *= smoothstep(1.0, 0.2, d);
  return m;
}

vec3 StarLayer(vec2 uv) {
  vec3 col = vec3(0.0);
  vec2 gv = fract(uv) - 0.5;
  vec2 id = floor(uv);
  for (int y = -1; y <= 1; y++) {
    for (int x = -1; x <= 1; x++) {
      vec2 offset = vec2(float(x), float(y));
      vec2 si = id + vec2(float(x), float(y));
      float seed = Hash21(si);
      float size = fract(seed * 345.32);
      float glossLocal = tri(uStarSpeed / (PERIOD * seed + 1.0));
      float flareSize = smoothstep(0.9, 1.0, size) * glossLocal;
      float red = smoothstep(STAR_COLOR_CUTOFF, 1.0, Hash21(si + 1.0)) + STAR_COLOR_CUTOFF;
      float blu = smoothstep(STAR_COLOR_CUTOFF, 1.0, Hash21(si + 3.0)) + STAR_COLOR_CUTOFF;
      float grn = min(red, blu) * seed;
      vec3 base = vec3(red, grn, blu);
      float hue = atan(base.g - base.r, base.b - base.r) / (2.0 * 3.14159) + 0.5;
      hue = fract(hue + uHueShift / 360.0);
      float sat = length(base - vec3(dot(base, vec3(0.299, 0.587, 0.114)))) * uSaturation;
      float val = max(max(base.r, base.g), base.b);
      base = hsv2rgb(vec3(hue, sat, val));
      vec2 pad = vec2(tris(seed * 34.0 + uTime * uSpeed / 10.0), tris(seed * 38.0 + uTime * uSpeed / 30.0)) - 0.5;
      float star = Star(gv - offset - pad, flareSize);
      vec3 color = base;
      float twinkle = trisn(uTime * uSpeed + seed * 6.2831) * 0.5 + 1.0;
      twinkle = mix(1.0, twinkle, uTwinkleIntensity);
      star *= twinkle;
      col += star * size * color;
    }
  }
  return col;
}

void main() {
  vec2 focalPx = uFocal * uResolution.xy;
  vec2 uv = (vUv * uResolution.xy - focalPx) / uResolution.y;
  vec2 mouseNorm = uMouse - vec2(0.5);
  if (uAutoCenterRepulsion > 0.0) {
    vec2 centerUV = vec2(0.0, 0.0);
    float centerDist = length(uv - centerUV);
    vec2 repulsion = normalize(uv - centerUV) * (uAutoCenterRepulsion / (centerDist + 0.1));
    uv += repulsion * 0.05;
  } else if (uMouseRepulsion) {
    vec2 mousePosUV = (uMouse * uResolution.xy - focalPx) / uResolution.y;
    float mouseDist = length(uv - mousePosUV);
    vec2 repulsion = normalize(uv - mousePosUV) * (uRepulsionStrength / (mouseDist + 0.1));
    uv += repulsion * 0.05 * uMouseActiveFactor;
  } else {
    vec2 mouseOffset = mouseNorm * 0.1 * uMouseActiveFactor;
    uv += mouseOffset;
  }
  float autoRotAngle = uTime * uRotationSpeed;
  mat2 autoRot = mat2(cos(autoRotAngle), -sin(autoRotAngle), sin(autoRotAngle), cos(autoRotAngle));
  uv = autoRot * uv;
  uv = mat2(uRotation.x, -uRotation.y, uRotation.y, uRotation.x) * uv;
  vec3 col = vec3(0.0);
  for (float i = 0.0; i < 1.0; i += 1.0 / NUM_LAYER) {
    float depth = fract(i + uStarSpeed * uSpeed);
    float scale = mix(20.0 * uDensity, 0.5 * uDensity, depth);
    float fade = depth * smoothstep(1.0, 0.9, depth);
    col += StarLayer(uv * scale + i * 453.32) * fade;
  }
  if (uTransparent) {
    float alpha = length(col);
    alpha = smoothstep(0.0, 0.3, alpha);
    alpha = min(alpha, 1.0);
    gl_FragColor = vec4(col, alpha);
  } else {
    gl_FragColor = vec4(col, 1.0);
  }
}
`;

export default function Galaxy(props: GalaxyProps) {
  const ctnDom = useRef<HTMLDivElement>(null);
  const targetMousePos = useRef({ x: 0.5, y: 0.5 });
  const smoothMousePos = useRef({ x: 0.5, y: 0.5 });
  const targetMouseActive = useRef(0.0);
  const smoothMouseActive = useRef(0.0);

  // propsRef pattern: live config flows into the RAF loop every frame.
  // `transparent` is the only setup-only prop (it controls renderer alpha
  // initialisation); everything else streams in via propsRef.
  const propsRef = useRef(props);
  propsRef.current = props;

  useEffect(() => {
    if (!ctnDom.current) return;
    const ctn = ctnDom.current;
    const initial = propsRef.current;
    const renderer = new Renderer({ alpha: initial.transparent !== false, premultipliedAlpha: false });
    const gl = renderer.gl;
    if (initial.transparent !== false) {
      gl.enable(gl.BLEND);
      gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);
      gl.clearColor(0, 0, 0, 0);
    } else {
      gl.clearColor(0, 0, 0, 1);
    }
    let program: any;
    function resize() {
      renderer.setSize(ctn.offsetWidth, ctn.offsetHeight);
      if (program) {
        program.uniforms.uResolution.value = new Color(gl.canvas.width, gl.canvas.height, gl.canvas.width / gl.canvas.height);
      }
    }
    window.addEventListener('resize', resize, false);
    resize();
    const geometry = new Triangle(gl);
    program = new Program(gl, {
      vertex: vertexShader,
      fragment: fragmentShader,
      uniforms: {
        uTime: { value: 0 },
        uResolution: { value: new Color(gl.canvas.width, gl.canvas.height, gl.canvas.width / gl.canvas.height) },
        uFocal: { value: new Float32Array(initial.focal ?? [0.5, 0.5]) },
        uRotation: { value: new Float32Array(initial.rotation ?? [1.0, 0.0]) },
        uStarSpeed: { value: initial.starSpeed ?? 0.5 },
        uDensity: { value: initial.density ?? 1 },
        uHueShift: { value: initial.hueShift ?? 140 },
        uSpeed: { value: initial.speed ?? 1.0 },
        uMouse: { value: new Float32Array([smoothMousePos.current.x, smoothMousePos.current.y]) },
        uGlowIntensity: { value: initial.glowIntensity ?? 0.3 },
        uSaturation: { value: initial.saturation ?? 0.0 },
        uMouseRepulsion: { value: initial.mouseRepulsion !== false },
        uTwinkleIntensity: { value: initial.twinkleIntensity ?? 0.3 },
        uRotationSpeed: { value: initial.rotationSpeed ?? 0.1 },
        uRepulsionStrength: { value: initial.repulsionStrength ?? 2 },
        uMouseActiveFactor: { value: 0.0 },
        uAutoCenterRepulsion: { value: initial.autoCenterRepulsion ?? 0 },
        uTransparent: { value: initial.transparent !== false }
      }
    });
    const mesh = new Mesh(gl, { geometry, program });
    let animateId: number;
    function update(t: number) {
      animateId = requestAnimationFrame(update);
      const live = propsRef.current;
      const liveStarSpeed = live.starSpeed ?? 0.5;
      if (!live.disableAnimation) {
        program.uniforms.uTime.value = t * 0.001;
        program.uniforms.uStarSpeed.value = (t * 0.001 * liveStarSpeed) / 10.0;
      }
      // Pull every reactive uniform from the latest props each frame.
      program.uniforms.uFocal.value[0] = (live.focal ?? [0.5, 0.5])[0];
      program.uniforms.uFocal.value[1] = (live.focal ?? [0.5, 0.5])[1];
      program.uniforms.uRotation.value[0] = (live.rotation ?? [1.0, 0.0])[0];
      program.uniforms.uRotation.value[1] = (live.rotation ?? [1.0, 0.0])[1];
      program.uniforms.uDensity.value = live.density ?? 1;
      program.uniforms.uHueShift.value = live.hueShift ?? 140;
      program.uniforms.uSpeed.value = live.speed ?? 1.0;
      program.uniforms.uGlowIntensity.value = live.glowIntensity ?? 0.3;
      program.uniforms.uSaturation.value = live.saturation ?? 0.0;
      program.uniforms.uMouseRepulsion.value = live.mouseRepulsion !== false;
      program.uniforms.uTwinkleIntensity.value = live.twinkleIntensity ?? 0.3;
      program.uniforms.uRotationSpeed.value = live.rotationSpeed ?? 0.1;
      program.uniforms.uRepulsionStrength.value = live.repulsionStrength ?? 2;
      program.uniforms.uAutoCenterRepulsion.value = live.autoCenterRepulsion ?? 0;
      const lerpFactor = 0.05;
      smoothMousePos.current.x += (targetMousePos.current.x - smoothMousePos.current.x) * lerpFactor;
      smoothMousePos.current.y += (targetMousePos.current.y - smoothMousePos.current.y) * lerpFactor;
      smoothMouseActive.current += (targetMouseActive.current - smoothMouseActive.current) * lerpFactor;
      program.uniforms.uMouse.value[0] = smoothMousePos.current.x;
      program.uniforms.uMouse.value[1] = smoothMousePos.current.y;
      program.uniforms.uMouseActiveFactor.value = smoothMouseActive.current;
      renderer.render({ scene: mesh });
    }
    animateId = requestAnimationFrame(update);
    ctn.appendChild(gl.canvas);
    function handleMouseMove(e: MouseEvent) {
      if (propsRef.current.mouseInteraction === false) return;
      const rect = ctn.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width;
      const y = 1.0 - (e.clientY - rect.top) / rect.height;
      targetMousePos.current = { x, y };
      targetMouseActive.current = 1.0;
    }
    function handleMouseLeave() { targetMouseActive.current = 0.0; }
    ctn.addEventListener('mousemove', handleMouseMove);
    ctn.addEventListener('mouseleave', handleMouseLeave);
    const onContextLost = (e: Event) => { e.preventDefault(); };
    gl.canvas.addEventListener('webglcontextlost', onContextLost);

    return () => {
      cancelAnimationFrame(animateId);
      window.removeEventListener('resize', resize);
      ctn.removeEventListener('mousemove', handleMouseMove);
      ctn.removeEventListener('mouseleave', handleMouseLeave);
      gl.canvas.removeEventListener('webglcontextlost', onContextLost);
      if (gl.canvas.parentNode === ctn) ctn.removeChild(gl.canvas);
      gl.getExtension('WEBGL_lose_context')?.loseContext();
    };
  }, []);

  const { focal: _f, rotation: _r, starSpeed: _ss, density: _de, hueShift: _hs, disableAnimation: _da, speed: _sp, mouseInteraction: _mi, glowIntensity: _gi, saturation: _sa, mouseRepulsion: _mr, repulsionStrength: _rs, twinkleIntensity: _ti, rotationSpeed: _rsp, autoCenterRepulsion: _acr, transparent: _t, ...rest } = props;
  void _f; void _r; void _ss; void _de; void _hs; void _da; void _sp; void _mi;
  void _gi; void _sa; void _mr; void _rs; void _ti; void _rsp; void _acr; void _t;

  return <div ref={ctnDom} style={{ width: '100%', height: '100%', position: 'relative' }} {...rest} />;
}
