/**
 * Plasma Background Effect
 * Source: https://github.com/DavidHDev/react-bits (MIT License)
 * Original: src/content/Backgrounds/Plasma/Plasma.jsx
 *
 * Kept 1:1 with the original, only typed for TypeScript.
 */

import { useEffect, useRef } from 'react';
import { Renderer, Program, Mesh, Triangle } from 'ogl';

interface PlasmaProps {
  color?: string; speed?: number; direction?: 'forward' | 'reverse' | 'pingpong';
  scale?: number; opacity?: number; mouseInteractive?: boolean;
}

const hexToRgb = (hex: string) => {
  const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
  if (!result) return [1, 0.5, 0.2];
  return [parseInt(result[1], 16) / 255, parseInt(result[2], 16) / 255, parseInt(result[3], 16) / 255];
};

const vertex = `#version 300 es
precision highp float;in vec2 position;in vec2 uv;out vec2 vUv;
void main(){vUv=uv;gl_Position=vec4(position,0.0,1.0);}
`;

const fragment = `#version 300 es
precision highp float;
uniform vec2 iResolution;uniform float iTime;uniform vec3 uCustomColor;uniform float uUseCustomColor;uniform float uSpeed;uniform float uDirection;uniform float uScale;uniform float uOpacity;uniform vec2 uMouse;uniform float uMouseInteractive;
out vec4 fragColor;
void mainImage(out vec4 o,vec2 C){
  vec2 center=iResolution.xy*0.5;C=(C-center)/uScale+center;
  vec2 mouseOffset=(uMouse-center)*0.0002;C+=mouseOffset*length(C-center)*step(0.5,uMouseInteractive);
  float i,d,z,T=iTime*uSpeed*uDirection;vec3 O,p,S;
  for(vec2 r=iResolution.xy,Q;++i<60.;O+=o.w/d*o.xyz){
    p=z*normalize(vec3(C-.5*r,r.y));p.z-=4.;S=p;d=p.y-T;
    p.x+=.4*(1.+p.y)*sin(d+p.x*0.1)*cos(.34*d+p.x*0.05);Q=p.xz*=mat2(cos(p.y+vec4(0,11,33,0)-T));
    z+=d=abs(sqrt(length(Q*Q))-.25*(5.+S.y))/3.+8e-4;o=1.+sin(S.y+p.z*.5+S.z-length(S-p)+vec4(2,1,0,8));
  }
  o.xyz=tanh(O/1e4);
}
bool finite1(float x){return !(isnan(x)||isinf(x));}
vec3 sanitize(vec3 c){return vec3(finite1(c.r)?c.r:0.0,finite1(c.g)?c.g:0.0,finite1(c.b)?c.b:0.0);}
void main(){
  vec4 o=vec4(0.0);mainImage(o,gl_FragCoord.xy);vec3 rgb=sanitize(o.rgb);
  float intensity=(rgb.r+rgb.g+rgb.b)/3.0;vec3 customColor=intensity*uCustomColor;
  vec3 finalColor=mix(rgb,customColor,step(0.5,uUseCustomColor));
  float alpha=length(rgb)*uOpacity;fragColor=vec4(finalColor,alpha);
}`;

export const Plasma = (props: PlasmaProps) => {
  const containerRef = useRef<HTMLDivElement>(null);
  const mousePos = useRef({ x: 0, y: 0 });
  const propsRef = useRef(props);
  propsRef.current = props;

  useEffect(() => {
    if (!containerRef.current) return;
    const containerEl = containerRef.current;
    const initial = propsRef.current;
    const renderer = new Renderer({ webgl: 2, alpha: true, antialias: false, dpr: Math.min(window.devicePixelRatio || 1, 2) });
    const gl = renderer.gl; const canvas = gl.canvas;
    canvas.style.display = 'block'; canvas.style.width = '100%'; canvas.style.height = '100%';
    containerRef.current.appendChild(canvas);
    const geometry = new Triangle(gl);
    const initialColor = initial.color ?? '#ffffff';
    const program = new Program(gl, { vertex, fragment, uniforms: {
      iTime: { value: 0 }, iResolution: { value: new Float32Array([1, 1]) },
      uCustomColor: { value: new Float32Array(hexToRgb(initialColor)) }, uUseCustomColor: { value: initialColor ? 1.0 : 0.0 },
      uSpeed: { value: (initial.speed ?? 1) * 0.4 }, uDirection: { value: initial.direction === 'reverse' ? -1.0 : 1.0 },
      uScale: { value: initial.scale ?? 1 }, uOpacity: { value: initial.opacity ?? 1 },
      uMouse: { value: new Float32Array([0, 0]) }, uMouseInteractive: { value: (initial.mouseInteractive ?? true) ? 1.0 : 0.0 }
    } });
    const mesh = new Mesh(gl, { geometry, program });
    const handleMouseMove = (e: MouseEvent) => {
      if (!propsRef.current.mouseInteractive) return;
      const rect = containerRef.current!.getBoundingClientRect();
      mousePos.current.x = e.clientX - rect.left; mousePos.current.y = e.clientY - rect.top;
      const mu = program.uniforms.uMouse.value; mu[0] = mousePos.current.x; mu[1] = mousePos.current.y;
    };
    containerEl.addEventListener('mousemove', handleMouseMove);
    const onContextLost = (e: Event) => { e.preventDefault(); };
    canvas.addEventListener('webglcontextlost', onContextLost);

    const setSize = () => {
      const rect = containerRef.current!.getBoundingClientRect();
      const w = Math.max(1, Math.floor(rect.width)); const h = Math.max(1, Math.floor(rect.height));
      renderer.setSize(w, h);
      const res = program.uniforms.iResolution.value; res[0] = gl.drawingBufferWidth; res[1] = gl.drawingBufferHeight;
    };
    const ro = new ResizeObserver(setSize); ro.observe(containerEl); setSize();
    let raf = 0; const t0 = performance.now();
    let lastColorKey = initialColor;
    const loop = (t: number) => {
      const live = propsRef.current;
      const dir = live.direction ?? 'forward';
      let timeValue = (t - t0) * 0.001 * (live.speed ?? 1);
      if (dir === 'pingpong') {
        const dur = 10; const seg = timeValue % dur; const isF = Math.floor(timeValue / dur) % 2 === 0;
        const u = seg / dur; const smooth = u * u * (3 - 2 * u);
        const ppTime = isF ? smooth * dur : (1 - smooth) * dur;
        program.uniforms.uDirection.value = 1.0; program.uniforms.iTime.value = ppTime;
      } else {
        program.uniforms.uDirection.value = dir === 'reverse' ? -1.0 : 1.0;
        program.uniforms.iTime.value = timeValue;
      }
      program.uniforms.uSpeed.value = (live.speed ?? 1) * 0.4;
      program.uniforms.uScale.value = live.scale ?? 1;
      program.uniforms.uOpacity.value = live.opacity ?? 1;
      program.uniforms.uMouseInteractive.value = (live.mouseInteractive ?? true) ? 1.0 : 0.0;
      const colorKey = live.color ?? '#ffffff';
      if (colorKey !== lastColorKey) {
        lastColorKey = colorKey;
        const rgb = hexToRgb(colorKey);
        program.uniforms.uCustomColor.value[0] = rgb[0];
        program.uniforms.uCustomColor.value[1] = rgb[1];
        program.uniforms.uCustomColor.value[2] = rgb[2];
        program.uniforms.uUseCustomColor.value = colorKey ? 1.0 : 0.0;
      }
      renderer.render({ scene: mesh }); raf = requestAnimationFrame(loop);
    };
    raf = requestAnimationFrame(loop);
    return () => {
      cancelAnimationFrame(raf); ro.disconnect();
      containerEl?.removeEventListener('mousemove', handleMouseMove);
      canvas.removeEventListener('webglcontextlost', onContextLost);
      gl.getExtension('WEBGL_lose_context')?.loseContext();
      try { containerEl?.removeChild(canvas); } catch { console.warn('Canvas already removed from container'); }
    };
  }, []);
  return <div ref={containerRef} style={{ position: 'relative', width: '100%', height: '100%', overflow: 'hidden' }} />;
};

export default Plasma;
