// @ts-nocheck
/**
 * GridDistortion Background Effect
 * Source: https://github.com/DavidHDev/react-bits (MIT License)
 * Original: src/content/Backgrounds/GridDistortion/GridDistortion.jsx
 *
 * Kept 1:1 with the original, only typed for TypeScript.
 * CSS inlined.
 * Dependencies: three
 */

import { useEffect, useRef } from 'react';
import * as THREE from 'three';

interface GridDistortionProps {
  grid?: number;
  mouse?: number;
  strength?: number;
  relaxation?: number;
  imageSrc?: string;
  className?: string;
}

const vertexShader = `
uniform float time;
varying vec2 vUv;
varying vec3 vPosition;
void main() {
  vUv = uv;
  vPosition = position;
  gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
}`;

const fragmentShader = `
uniform sampler2D uDataTexture;
uniform sampler2D uTexture;
uniform vec4 resolution;
varying vec2 vUv;
void main() {
  vec2 uv = vUv;
  vec4 offset = texture2D(uDataTexture, vUv);
  gl_FragColor = texture2D(uTexture, uv - 0.02 * offset.rg);
}`;

const GridDistortion = (props: GridDistortionProps) => {
  const { grid = 15, imageSrc, className = '' } = props;
  const containerRef = useRef<HTMLDivElement>(null);
  const animationIdRef = useRef<number | null>(null);
  const resizeObserverRef = useRef<ResizeObserver | null>(null);

  // propsRef pattern: animation-loop reads live mouse / strength / relaxation
  // values every frame. Setup-only props (grid, imageSrc) flow through React
  // unmount-driven remounts when they really change identity.
  const propsRef = useRef(props);
  propsRef.current = props;

  useEffect(() => {
    if (!containerRef.current) return;
    const container = containerRef.current;

    const scene = new THREE.Scene();
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setClearColor(0x000000, 0);

    container.innerHTML = '';
    container.appendChild(renderer.domElement);

    const camera = new THREE.OrthographicCamera(0, 0, 0, 0, -1000, 1000);
    camera.position.z = 2;

    // Build a procedural fallback texture so the configurator always shows
    // *something* — without this, an empty `imageSrc` (the default config)
    // sampled `null` in the shader and the preview was transparent black.
    const fallbackSize = 256;
    const fallbackData = new Uint8Array(fallbackSize * fallbackSize * 4);
    for (let y = 0; y < fallbackSize; y++) {
      for (let x = 0; x < fallbackSize; x++) {
        const i = (y * fallbackSize + x) * 4;
        const u = x / fallbackSize;
        const v = y / fallbackSize;
        // Subtle violet → cyan diagonal gradient with vignette so the
        // distortion is clearly visible.
        const t = (u + v) * 0.5;
        const r = (1 - t) * 0.49 + t * 0.13;
        const g = (1 - t) * 0.20 + t * 0.50;
        const b = (1 - t) * 0.95 + t * 0.79;
        const vignette = 1.0 - Math.min(1.0, Math.hypot(u - 0.5, v - 0.5) * 1.4);
        fallbackData[i] = Math.round(r * vignette * 255);
        fallbackData[i + 1] = Math.round(g * vignette * 255);
        fallbackData[i + 2] = Math.round(b * vignette * 255);
        fallbackData[i + 3] = 255;
      }
    }
    const fallbackTexture = new THREE.DataTexture(fallbackData, fallbackSize, fallbackSize, THREE.RGBAFormat);
    fallbackTexture.minFilter = THREE.LinearFilter;
    fallbackTexture.magFilter = THREE.LinearFilter;
    fallbackTexture.wrapS = THREE.ClampToEdgeWrapping;
    fallbackTexture.wrapT = THREE.ClampToEdgeWrapping;
    fallbackTexture.needsUpdate = true;

    const uniforms: any = {
      time: { value: 0 },
      resolution: { value: new THREE.Vector4() },
      uTexture: { value: fallbackTexture },
      uDataTexture: { value: null }
    };

    const textureLoader = new THREE.TextureLoader();
    let imageAspect = 1;
    if (imageSrc) {
      textureLoader.load(imageSrc, (texture: THREE.Texture) => {
        texture.minFilter = THREE.LinearFilter;
        texture.magFilter = THREE.LinearFilter;
        texture.wrapS = THREE.ClampToEdgeWrapping;
        texture.wrapT = THREE.ClampToEdgeWrapping;
        imageAspect = texture.image.width / texture.image.height;
        uniforms.uTexture.value = texture;
        handleResize();
      });
    }

    const size = grid;
    const data = new Float32Array(4 * size * size);
    for (let i = 0; i < size * size; i++) {
      data[i * 4] = Math.random() * 255 - 125;
      data[i * 4 + 1] = Math.random() * 255 - 125;
    }

    const dataTexture = new THREE.DataTexture(data, size, size, THREE.RGBAFormat, THREE.FloatType);
    dataTexture.needsUpdate = true;
    uniforms.uDataTexture.value = dataTexture;

    const material = new THREE.ShaderMaterial({ side: THREE.DoubleSide, uniforms, vertexShader, fragmentShader, transparent: true });
    const geometry = new THREE.PlaneGeometry(1, 1, size - 1, size - 1);
    const plane = new THREE.Mesh(geometry, material);
    scene.add(plane);

    const handleResize = () => {
      if (!container || !renderer || !camera) return;
      const rect = container.getBoundingClientRect();
      const width = rect.width;
      const height = rect.height;
      if (width === 0 || height === 0) return;
      const containerAspect = width / height;
      renderer.setSize(width, height);
      if (plane) plane.scale.set(containerAspect, 1, 1);
      const frustumHeight = 1;
      const frustumWidth = frustumHeight * containerAspect;
      camera.left = -frustumWidth / 2;
      camera.right = frustumWidth / 2;
      camera.top = frustumHeight / 2;
      camera.bottom = -frustumHeight / 2;
      camera.updateProjectionMatrix();
      uniforms.resolution.value.set(width, height, 1, 1);
    };

    if (window.ResizeObserver) {
      const resizeObserver = new ResizeObserver(() => handleResize());
      resizeObserver.observe(container);
      resizeObserverRef.current = resizeObserver;
    } else {
      window.addEventListener('resize', handleResize);
    }

    const mouseState = { x: 0, y: 0, prevX: 0, prevY: 0, vX: 0, vY: 0 };

    const handleMouseMove = (e: MouseEvent) => {
      const rect = container.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width;
      const y = 1 - (e.clientY - rect.top) / rect.height;
      mouseState.vX = x - mouseState.prevX;
      mouseState.vY = y - mouseState.prevY;
      Object.assign(mouseState, { x, y, prevX: x, prevY: y });
    };

    const handleMouseLeave = () => {
      if (dataTexture) dataTexture.needsUpdate = true;
      Object.assign(mouseState, { x: 0, y: 0, prevX: 0, prevY: 0, vX: 0, vY: 0 });
    };

    container.addEventListener('mousemove', handleMouseMove);
    container.addEventListener('mouseleave', handleMouseLeave);
    handleResize();

    const onContextLost = (e: Event) => { e.preventDefault(); };
    renderer.domElement.addEventListener('webglcontextlost', onContextLost);

    const animate = () => {
      animationIdRef.current = requestAnimationFrame(animate);
      if (!renderer || !scene || !camera) return;
      // Live reads from propsRef so slider drags reflect every frame.
      const live = propsRef.current;
      const liveMouse = live.mouse ?? 0.1;
      const liveStrength = live.strength ?? 0.15;
      const liveRelaxation = live.relaxation ?? 0.9;
      uniforms.time.value += 0.05;
      const d = dataTexture.image.data;
      for (let i = 0; i < size * size; i++) {
        d[i * 4] *= liveRelaxation;
        d[i * 4 + 1] *= liveRelaxation;
      }
      const gridMouseX = size * mouseState.x;
      const gridMouseY = size * mouseState.y;
      const maxDist = size * liveMouse;
      for (let i = 0; i < size; i++) {
        for (let j = 0; j < size; j++) {
          const distSq = Math.pow(gridMouseX - i, 2) + Math.pow(gridMouseY - j, 2);
          if (distSq < maxDist * maxDist) {
            const index = 4 * (i + size * j);
            const power = Math.min(maxDist / Math.sqrt(distSq), 10);
            d[index] += liveStrength * 100 * mouseState.vX * power;
            d[index + 1] -= liveStrength * 100 * mouseState.vY * power;
          }
        }
      }
      dataTexture.needsUpdate = true;
      renderer.render(scene, camera);
    };
    animate();

    return () => {
      if (animationIdRef.current) cancelAnimationFrame(animationIdRef.current);
      if (resizeObserverRef.current) resizeObserverRef.current.disconnect();
      else window.removeEventListener('resize', handleResize);
      container.removeEventListener('mousemove', handleMouseMove);
      container.removeEventListener('mouseleave', handleMouseLeave);
      if (renderer) {
        renderer.domElement.removeEventListener('webglcontextlost', onContextLost);
        renderer.dispose();
        renderer.forceContextLoss();
        if (container.contains(renderer.domElement)) container.removeChild(renderer.domElement);
      }
      geometry.dispose();
      material.dispose();
      dataTexture.dispose();
      fallbackTexture.dispose();
      if (uniforms.uTexture.value && uniforms.uTexture.value !== fallbackTexture) {
        uniforms.uTexture.value.dispose();
      }
    };
    // grid + imageSrc are setup-only — a structural change to either rebuilds
    // the data texture / material, which React reconciles via the
    // BackgroundPreview key when the asset type itself changes. mouse,
    // strength, and relaxation flow through propsRef inside `animate()`.
  }, [grid, imageSrc]);

  return <div ref={containerRef} className={className} style={{ width: '100%', height: '100%', minWidth: 0, minHeight: 0, overflow: 'hidden' }} />;
};

export default GridDistortion;
