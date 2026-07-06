/**
 * Radar Background Effect
 * Source: https://github.com/DavidHDev/react-bits (MIT License)
 * Original: src/content/Backgrounds/Radar/Radar.jsx
 *
 * Kept 1:1 with the original, only typed for TypeScript.
 */

import { Renderer, Program, Mesh, Triangle } from 'ogl';
import { useEffect, useRef } from 'react';

interface RadarProps {
  speed?: number;
  scale?: number;
  ringCount?: number;
  spokeCount?: number;
  ringThickness?: number;
  spokeThickness?: number;
  sweepSpeed?: number;
  sweepWidth?: number;
  sweepLobes?: number;
  color?: string;
  backgroundColor?: string;
  falloff?: number;
  brightness?: number;
  enableMouseInteraction?: boolean;
  mouseInfluence?: number;
}

function hexToVec3(hex: string) {
  const h = hex.replace('#', '');
  return [parseInt(h.slice(0, 2), 16) / 255, parseInt(h.slice(2, 4), 16) / 255, parseInt(h.slice(4, 6), 16) / 255];
}

const vertexShader = `
attribute vec2 uv;
attribute vec2 position;
varying vec2 vUv;
void main() { vUv = uv; gl_Position = vec4(position, 0, 1); }
`;

const fragmentShader = `
precision highp float;
uniform float uTime;uniform vec3 uResolution;uniform float uSpeed;uniform float uScale;uniform float uRingCount;uniform float uSpokeCount;uniform float uRingThickness;uniform float uSpokeThickness;uniform float uSweepSpeed;uniform float uSweepWidth;uniform float uSweepLobes;uniform vec3 uColor;uniform vec3 uBgColor;uniform float uFalloff;uniform float uBrightness;uniform vec2 uMouse;uniform float uMouseInfluence;uniform bool uEnableMouse;
#define TAU 6.28318530718
#define PI 3.14159265359
void main(){
  vec2 st=gl_FragCoord.xy/uResolution.xy;st=st*2.0-1.0;st.x*=uResolution.x/uResolution.y;
  if(uEnableMouse){vec2 mShift=(uMouse*2.0-1.0);mShift.x*=uResolution.x/uResolution.y;st-=mShift*uMouseInfluence;}
  st*=uScale;float dist=length(st);float theta=atan(st.y,st.x);float t=uTime*uSpeed;
  float ringPhase=dist*uRingCount-t;float ringDist=abs(fract(ringPhase)-0.5);float ringGlow=1.0-smoothstep(0.0,uRingThickness,ringDist);
  float spokeAngle=abs(fract(theta*uSpokeCount/TAU+0.5)-0.5)*TAU/uSpokeCount;float arcDist=spokeAngle*dist;float spokeGlow=(1.0-smoothstep(0.0,uSpokeThickness,arcDist))*smoothstep(0.0,0.1,dist);
  float sweepPhase=t*uSweepSpeed;float sweepBeam=pow(max(0.5*sin(uSweepLobes*theta+sweepPhase)+0.5,0.0),uSweepWidth);
  float fade=smoothstep(1.05,0.85,dist)*pow(max(1.0-dist,0.0),uFalloff);
  float intensity=max((ringGlow+spokeGlow+sweepBeam)*fade*uBrightness,0.0);vec3 col=uColor*intensity+uBgColor;
  float alpha=clamp(length(col),0.0,1.0);gl_FragColor=vec4(col,alpha);
}
`;

export default function Radar(props: RadarProps) {
  const containerRef = useRef<HTMLDivElement>(null);
  const propsRef = useRef(props);
  propsRef.current = props;

  useEffect(() => {
    if (!containerRef.current) return;
    const container = containerRef.current;
    const renderer = new Renderer({ alpha: true, premultipliedAlpha: false });
    const gl = renderer.gl; gl.clearColor(0, 0, 0, 0);
    let program: any; let currentMouse = [0.5, 0.5]; let targetMouse = [0.5, 0.5];
    function handleMouseMove(e: MouseEvent) {
      if (propsRef.current.enableMouseInteraction === false) return;
      const rect = gl.canvas.getBoundingClientRect();
      targetMouse = [(e.clientX - rect.left) / rect.width, 1.0 - (e.clientY - rect.top) / rect.height];
    }
    function handleMouseLeave() { targetMouse = [0.5, 0.5]; }
    function resize() { renderer.setSize(container.offsetWidth, container.offsetHeight); if (program) program.uniforms.uResolution.value = [gl.canvas.width, gl.canvas.height, gl.canvas.width / gl.canvas.height]; }
    window.addEventListener('resize', resize); resize();
    const initial = propsRef.current;
    const geometry = new Triangle(gl);
    program = new Program(gl, { vertex: vertexShader, fragment: fragmentShader, uniforms: {
      uTime: { value: 0 }, uResolution: { value: [gl.canvas.width, gl.canvas.height, gl.canvas.width / gl.canvas.height] },
      uSpeed: { value: initial.speed ?? 1.0 }, uScale: { value: initial.scale ?? 0.5 },
      uRingCount: { value: initial.ringCount ?? 10.0 }, uSpokeCount: { value: initial.spokeCount ?? 10.0 },
      uRingThickness: { value: initial.ringThickness ?? 0.05 }, uSpokeThickness: { value: initial.spokeThickness ?? 0.01 },
      uSweepSpeed: { value: initial.sweepSpeed ?? 1.0 }, uSweepWidth: { value: initial.sweepWidth ?? 2.0 },
      uSweepLobes: { value: initial.sweepLobes ?? 1.0 }, uColor: { value: hexToVec3(initial.color ?? '#9f29ff') },
      uBgColor: { value: hexToVec3(initial.backgroundColor ?? '#000000') }, uFalloff: { value: initial.falloff ?? 2.0 },
      uBrightness: { value: initial.brightness ?? 1.0 }, uMouse: { value: new Float32Array([0.5, 0.5]) },
      uMouseInfluence: { value: initial.mouseInfluence ?? 0.1 }, uEnableMouse: { value: initial.enableMouseInteraction !== false }
    } });
    const mesh = new Mesh(gl, { geometry, program }); container.appendChild(gl.canvas);
    gl.canvas.addEventListener('mousemove', handleMouseMove);
    gl.canvas.addEventListener('mouseleave', handleMouseLeave);
    const onContextLost = (e: Event) => { e.preventDefault(); };
    gl.canvas.addEventListener('webglcontextlost', onContextLost);

    let animationFrameId: number;
    let lastColor = initial.color ?? '#9f29ff';
    let lastBg = initial.backgroundColor ?? '#000000';
    function update(time: number) {
      animationFrameId = requestAnimationFrame(update);
      program.uniforms.uTime.value = time * 0.001;
      const live = propsRef.current;
      program.uniforms.uSpeed.value = live.speed ?? 1.0;
      program.uniforms.uScale.value = live.scale ?? 0.5;
      program.uniforms.uRingCount.value = live.ringCount ?? 10.0;
      program.uniforms.uSpokeCount.value = live.spokeCount ?? 10.0;
      program.uniforms.uRingThickness.value = live.ringThickness ?? 0.05;
      program.uniforms.uSpokeThickness.value = live.spokeThickness ?? 0.01;
      program.uniforms.uSweepSpeed.value = live.sweepSpeed ?? 1.0;
      program.uniforms.uSweepWidth.value = live.sweepWidth ?? 2.0;
      program.uniforms.uSweepLobes.value = live.sweepLobes ?? 1.0;
      program.uniforms.uFalloff.value = live.falloff ?? 2.0;
      program.uniforms.uBrightness.value = live.brightness ?? 1.0;
      program.uniforms.uMouseInfluence.value = live.mouseInfluence ?? 0.1;
      program.uniforms.uEnableMouse.value = live.enableMouseInteraction !== false;
      const c = live.color ?? '#9f29ff';
      if (c !== lastColor) { lastColor = c; program.uniforms.uColor.value = hexToVec3(c); }
      const bg = live.backgroundColor ?? '#000000';
      if (bg !== lastBg) { lastBg = bg; program.uniforms.uBgColor.value = hexToVec3(bg); }
      if (live.enableMouseInteraction !== false) {
        currentMouse[0] += 0.05 * (targetMouse[0] - currentMouse[0]);
        currentMouse[1] += 0.05 * (targetMouse[1] - currentMouse[1]);
        program.uniforms.uMouse.value[0] = currentMouse[0];
        program.uniforms.uMouse.value[1] = currentMouse[1];
      } else {
        program.uniforms.uMouse.value[0] = 0.5;
        program.uniforms.uMouse.value[1] = 0.5;
      }
      renderer.render({ scene: mesh });
    }
    animationFrameId = requestAnimationFrame(update);
    return () => {
      cancelAnimationFrame(animationFrameId); window.removeEventListener('resize', resize);
      gl.canvas.removeEventListener('mousemove', handleMouseMove);
      gl.canvas.removeEventListener('mouseleave', handleMouseLeave);
      gl.canvas.removeEventListener('webglcontextlost', onContextLost);
      if (gl.canvas.parentNode === container) container.removeChild(gl.canvas);
      gl.getExtension('WEBGL_lose_context')?.loseContext();
    };
  }, []);
  return <div ref={containerRef} style={{ width: '100%', height: '100%' }} />;
}
