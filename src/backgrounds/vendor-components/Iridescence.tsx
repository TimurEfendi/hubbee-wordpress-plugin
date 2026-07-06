/**
 * Iridescence Background Effect
 * Source: https://github.com/DavidHDev/react-bits (MIT License)
 * Original: src/content/Backgrounds/Iridescence/Iridescence.jsx
 *
 * Kept 1:1 with the original, only typed for TypeScript.
 * CSS inlined — no external stylesheet needed.
 */

import { Renderer, Program, Mesh, Color, Triangle } from 'ogl';
import { useEffect, useRef } from 'react';
import { normalizeColor } from '../../lib/normalize-config';

interface IridescenceProps {
  /**
   * Color input: accepts `[r, g, b]` tuple (0-1 each) or hex string
   * (e.g. "#5227FF"). Typed as `unknown` so the effect entrypoint can pass
   * raw config values through — `normalizeColor` handles the conversion
   * and falls back to `[1, 1, 1]` on anything unparseable.
   */
  color?: unknown;
  speed?: number;
  amplitude?: number;
  mouseReact?: boolean;
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
uniform vec3 uColor;
uniform vec3 uResolution;
uniform vec2 uMouse;
uniform float uAmplitude;
uniform float uSpeed;

varying vec2 vUv;

void main() {
  float mr = min(uResolution.x, uResolution.y);
  vec2 uv = (vUv.xy * 2.0 - 1.0) * uResolution.xy / mr;

  uv += (uMouse - vec2(0.5)) * uAmplitude;

  float d = -uTime * 0.5 * uSpeed;
  float a = 0.0;
  for (float i = 0.0; i < 8.0; ++i) {
    a += cos(i - d - a * uv.x);
    d += sin(uv.y * i + a);
  }
  d += uTime * 0.5 * uSpeed;
  vec3 col = vec3(cos(uv * vec2(d, a)) * 0.6 + 0.4, cos(a + d) * 0.5 + 0.5);
  col = cos(col * cos(vec3(d, a, 2.5)) * 0.5 + 0.5) * uColor;
  gl_FragColor = vec4(col, 1.0);
}
`;

export default function Iridescence(props: IridescenceProps) {
  const ctnDom = useRef<HTMLDivElement>(null);
  const mousePos = useRef({ x: 0.5, y: 0.5 });

  // propsRef pattern: the animation loop reads color / speed / amplitude /
  // mouseReact live from this ref. Setup runs exactly once.
  const propsRef = useRef(props);
  propsRef.current = props;

  useEffect(() => {
    if (!ctnDom.current) return;
    const ctn = ctnDom.current;
    const renderer = new Renderer();
    const gl = renderer.gl;
    gl.clearColor(1, 1, 1, 1);

    let program: any;

    function resize() {
      const scale = 1;
      renderer.setSize(ctn.offsetWidth * scale, ctn.offsetHeight * scale);
      if (program) {
        program.uniforms.uResolution.value = new Color(
          gl.canvas.width,
          gl.canvas.height,
          gl.canvas.width / gl.canvas.height
        );
      }
    }
    window.addEventListener('resize', resize, false);
    resize();

    const initial = propsRef.current;
    const initialColor = normalizeColor(initial.color, [1, 1, 1]);
    const geometry = new Triangle(gl);
    program = new Program(gl, {
      vertex: vertexShader,
      fragment: fragmentShader,
      uniforms: {
        uTime: { value: 0 },
        uColor: { value: new Color(...initialColor) },
        uResolution: {
          value: new Color(gl.canvas.width, gl.canvas.height, gl.canvas.width / gl.canvas.height)
        },
        uMouse: { value: new Float32Array([mousePos.current.x, mousePos.current.y]) },
        uAmplitude: { value: initial.amplitude ?? 0.1 },
        uSpeed: { value: initial.speed ?? 1.0 }
      }
    });

    const mesh = new Mesh(gl, { geometry, program });
    let animateId: number;
    let lastColorKey = JSON.stringify(initial.color ?? null);

    function update(t: number) {
      animateId = requestAnimationFrame(update);
      const live = propsRef.current;
      program.uniforms.uTime.value = t * 0.001;
      program.uniforms.uSpeed.value = live.speed ?? 1.0;
      program.uniforms.uAmplitude.value = live.amplitude ?? 0.1;
      const colorKey = JSON.stringify(live.color ?? null);
      if (colorKey !== lastColorKey) {
        lastColorKey = colorKey;
        const c = normalizeColor(live.color, [1, 1, 1]);
        program.uniforms.uColor.value.r = c[0];
        program.uniforms.uColor.value.g = c[1];
        program.uniforms.uColor.value.b = c[2];
      }
      renderer.render({ scene: mesh });
    }
    animateId = requestAnimationFrame(update);
    ctn.appendChild(gl.canvas);

    function handleMouseMove(e: MouseEvent) {
      if (!propsRef.current.mouseReact) return;
      const rect = ctn.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width;
      const y = 1.0 - (e.clientY - rect.top) / rect.height;
      mousePos.current = { x, y };
      program.uniforms.uMouse.value[0] = x;
      program.uniforms.uMouse.value[1] = y;
    }
    ctn.addEventListener('mousemove', handleMouseMove);
    const onContextLost = (e: Event) => { e.preventDefault(); };
    gl.canvas.addEventListener('webglcontextlost', onContextLost);

    return () => {
      cancelAnimationFrame(animateId);
      window.removeEventListener('resize', resize);
      ctn.removeEventListener('mousemove', handleMouseMove);
      gl.canvas.removeEventListener('webglcontextlost', onContextLost);
      if (gl.canvas.parentNode === ctn) ctn.removeChild(gl.canvas);
      gl.getExtension('WEBGL_lose_context')?.loseContext();
    };
  }, []);

  const { color: _ignoredColor, speed: _ignoredSpeed, amplitude: _ignoredAmp, mouseReact: _ignoredMr, ...rest } = props;
  void _ignoredColor; void _ignoredSpeed; void _ignoredAmp; void _ignoredMr;

  return (
    <div
      ref={ctnDom}
      style={{ width: '100%', height: '100%' }}
      {...rest}
    />
  );
}
