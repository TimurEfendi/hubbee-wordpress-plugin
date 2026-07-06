/**
 * LiquidChrome Background Effect
 * Source: https://github.com/DavidHDev/react-bits (MIT License)
 * Original: src/content/Backgrounds/LiquidChrome/LiquidChrome.jsx
 *
 * Kept 1:1 with the original, only typed for TypeScript.
 * CSS inlined — no external stylesheet needed.
 */

import { useRef, useEffect } from 'react';
import { Renderer, Program, Mesh, Triangle } from 'ogl';
import { normalizeColor } from '../../lib/normalize-config';

interface LiquidChromeProps {
  /** See `IridescenceProps.color` — typed `unknown`, normalised internally. */
  baseColor?: unknown;
  speed?: number;
  amplitude?: number;
  frequencyX?: number;
  frequencyY?: number;
  interactive?: boolean;
  [key: string]: unknown;
}

export const LiquidChrome = (props: LiquidChromeProps) => {
  const containerRef = useRef<HTMLDivElement>(null);
  const propsRef = useRef(props);
  propsRef.current = props;

  useEffect(() => {
    if (!containerRef.current) return;

    const container = containerRef.current;
    const renderer = new Renderer({ antialias: true });
    const gl = renderer.gl;
    gl.clearColor(1, 1, 1, 1);

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
      precision highp float;
      uniform float uTime;
      uniform vec3 uResolution;
      uniform vec3 uBaseColor;
      uniform float uAmplitude;
      uniform float uFrequencyX;
      uniform float uFrequencyY;
      uniform vec2 uMouse;
      varying vec2 vUv;

      vec4 renderImage(vec2 uvCoord) {
          vec2 fragCoord = uvCoord * uResolution.xy;
          vec2 uv = (2.0 * fragCoord - uResolution.xy) / min(uResolution.x, uResolution.y);

          for (float i = 1.0; i < 10.0; i++){
              uv.x += uAmplitude / i * cos(i * uFrequencyX * uv.y + uTime + uMouse.x * 3.14159);
              uv.y += uAmplitude / i * cos(i * uFrequencyY * uv.x + uTime + uMouse.y * 3.14159);
          }

          vec2 diff = (uvCoord - uMouse);
          float dist = length(diff);
          float falloff = exp(-dist * 20.0);
          float ripple = sin(10.0 * dist - uTime * 2.0) * 0.03;
          uv += (diff / (dist + 0.0001)) * ripple * falloff;

          vec3 color = uBaseColor / abs(sin(uTime - uv.y - uv.x));
          return vec4(color, 1.0);
      }

      void main() {
          vec4 col = vec4(0.0);
          int samples = 0;
          for (int i = -1; i <= 1; i++){
              for (int j = -1; j <= 1; j++){
                  vec2 offset = vec2(float(i), float(j)) * (1.0 / min(uResolution.x, uResolution.y));
                  col += renderImage(vUv + offset);
                  samples++;
              }
          }
          gl_FragColor = col / float(samples);
      }
    `;

    const initial = propsRef.current;
    const initialBase = normalizeColor(initial.baseColor, [0.1, 0.1, 0.1]);
    const geometry = new Triangle(gl);
    const program = new Program(gl, {
      vertex: vertexShader,
      fragment: fragmentShader,
      uniforms: {
        uTime: { value: 0 },
        uResolution: {
          value: new Float32Array([gl.canvas.width, gl.canvas.height, gl.canvas.width / gl.canvas.height])
        },
        uBaseColor: { value: new Float32Array(initialBase) },
        uAmplitude: { value: initial.amplitude ?? 0.3 },
        uFrequencyX: { value: initial.frequencyX ?? 3 },
        uFrequencyY: { value: initial.frequencyY ?? 3 },
        uMouse: { value: new Float32Array([0, 0]) }
      }
    });
    const mesh = new Mesh(gl, { geometry, program });

    function resize() {
      const scale = 1;
      renderer.setSize(container.offsetWidth * scale, container.offsetHeight * scale);
      const resUniform = program.uniforms.uResolution.value as Float32Array;
      resUniform[0] = gl.canvas.width;
      resUniform[1] = gl.canvas.height;
      resUniform[2] = gl.canvas.width / gl.canvas.height;
    }
    window.addEventListener('resize', resize);
    resize();

    function handleMouseMove(event: MouseEvent) {
      if (propsRef.current.interactive === false) return;
      const rect = container.getBoundingClientRect();
      const x = (event.clientX - rect.left) / rect.width;
      const y = 1 - (event.clientY - rect.top) / rect.height;
      const mouseUniform = program.uniforms.uMouse.value as Float32Array;
      mouseUniform[0] = x;
      mouseUniform[1] = y;
    }

    function handleTouchMove(event: TouchEvent) {
      if (propsRef.current.interactive === false) return;
      if (event.touches.length > 0) {
        const touch = event.touches[0];
        const rect = container.getBoundingClientRect();
        const x = (touch.clientX - rect.left) / rect.width;
        const y = 1 - (touch.clientY - rect.top) / rect.height;
        const mouseUniform = program.uniforms.uMouse.value as Float32Array;
        mouseUniform[0] = x;
        mouseUniform[1] = y;
      }
    }

    container.addEventListener('mousemove', handleMouseMove);
    container.addEventListener('touchmove', handleTouchMove);
    const onContextLost = (e: Event) => { e.preventDefault(); };
    gl.canvas.addEventListener('webglcontextlost', onContextLost);

    let animationId: number;
    let lastBaseKey = JSON.stringify(initial.baseColor ?? null);
    function update(t: number) {
      animationId = requestAnimationFrame(update);
      const live = propsRef.current;
      program.uniforms.uTime.value = t * 0.001 * (live.speed ?? 0.2);
      program.uniforms.uAmplitude.value = live.amplitude ?? 0.3;
      program.uniforms.uFrequencyX.value = live.frequencyX ?? 3;
      program.uniforms.uFrequencyY.value = live.frequencyY ?? 3;
      const baseKey = JSON.stringify(live.baseColor ?? null);
      if (baseKey !== lastBaseKey) {
        lastBaseKey = baseKey;
        const c = normalizeColor(live.baseColor, [0.1, 0.1, 0.1]);
        const bc = program.uniforms.uBaseColor.value as Float32Array;
        bc[0] = c[0]; bc[1] = c[1]; bc[2] = c[2];
      }
      renderer.render({ scene: mesh });
    }
    animationId = requestAnimationFrame(update);

    container.appendChild(gl.canvas);

    return () => {
      cancelAnimationFrame(animationId);
      window.removeEventListener('resize', resize);
      container.removeEventListener('mousemove', handleMouseMove);
      container.removeEventListener('touchmove', handleTouchMove);
      gl.canvas.removeEventListener('webglcontextlost', onContextLost);
      if (gl.canvas.parentElement) {
        gl.canvas.parentElement.removeChild(gl.canvas);
      }
      gl.getExtension('WEBGL_lose_context')?.loseContext();
    };
  }, []);

  const { baseColor: _bc, speed: _sp, amplitude: _a, frequencyX: _fx, frequencyY: _fy, interactive: _i, ...rest } = props;
  void _bc; void _sp; void _a; void _fx; void _fy; void _i;
  return (
    <div
      ref={containerRef}
      style={{ width: '100%', height: '100%' }}
      {...rest}
    />
  );
};

export default LiquidChrome;
