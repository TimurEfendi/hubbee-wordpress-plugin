// @ts-nocheck
/**
 * Ballpit Background Effect
 * Source: https://github.com/DavidHDev/react-bits (MIT License)
 * Original: src/content/Backgrounds/Ballpit/Ballpit.jsx
 *
 * Kept 1:1 with the original, only typed for TypeScript.
 * Dependencies: three, three/examples/jsm/environments/RoomEnvironment.js
 */

import { useEffect, useRef } from 'react';
import {
  Vector3 as a,
  MeshPhysicalMaterial as c,
  InstancedMesh as d,
  Clock as e,
  AmbientLight as f,
  SphereGeometry as g,
  ShaderChunk as h,
  Scene as i,
  Color as l,
  Object3D as m,
  SRGBColorSpace as n,
  MathUtils as o,
  PMREMGenerator as p,
  Vector2 as r,
  WebGLRenderer as s,
  PerspectiveCamera as t,
  PointLight as u,
  ACESFilmicToneMapping as v,
  Plane as w,
  Raycaster as y
} from 'three';
import { RoomEnvironment as z } from 'three/examples/jsm/environments/RoomEnvironment.js';
import { normalizeColorArray } from '../../lib/normalize-config';

interface BallpitProps {
  className?: string;
  followCursor?: boolean;
  count?: number;
  /** Accepts numeric values (`0xRRGGBB`) or hex strings; normalised internally. */
  colors?: unknown;
  ambientColor?: number;
  ambientIntensity?: number;
  lightIntensity?: number;
  materialParams?: Record<string, number>;
  minSize?: number;
  maxSize?: number;
  size0?: number;
  gravity?: number;
  friction?: number;
  wallBounce?: number;
  maxVelocity?: number;
  maxX?: number;
  maxY?: number;
  maxZ?: number;
  [key: string]: unknown;
}

class x {
  #e: any;
  canvas: any;
  camera: any;
  cameraMinAspect: any;
  cameraMaxAspect: any;
  cameraFov: any;
  maxPixelRatio: any;
  minPixelRatio: any;
  scene: any;
  renderer: any;
  #t: any;
  size: any = { width: 0, height: 0, wWidth: 0, wHeight: 0, ratio: 0, pixelRatio: 0 };
  render: any = this.#i;
  onBeforeRender: any = () => {};
  onAfterRender: any = () => {};
  onAfterResize: any = () => {};
  #s = false;
  #n = false;
  isDisposed = false;
  #o: any;
  #r: any;
  #a: any;
  #c = new e();
  #h: any = { elapsed: 0, delta: 0 };
  #l: any;
  constructor(e1: any) {
    this.#e = { ...e1 };
    this.#m();
    this.#d();
    this.#p();
    this.resize();
    this.#g();
  }
  #m() {
    this.camera = new t();
    this.cameraFov = this.camera.fov;
  }
  #d() {
    this.scene = new i();
  }
  #p() {
    if (this.#e.canvas) {
      this.canvas = this.#e.canvas;
    } else if (this.#e.id) {
      this.canvas = document.getElementById(this.#e.id);
    } else {
      console.error('Three: Missing canvas or id parameter');
    }
    this.canvas.style.display = 'block';
    const e1: any = {
      canvas: this.canvas,
      powerPreference: 'high-performance',
      ...(this.#e.rendererOptions ?? {})
    };
    this.renderer = new s(e1);
    this.renderer.outputColorSpace = n;
  }
  #g() {
    if (!(this.#e.size instanceof Object)) {
      window.addEventListener('resize', this.#f.bind(this));
      if (this.#e.size === 'parent' && this.canvas.parentNode) {
        this.#r = new ResizeObserver(this.#f.bind(this));
        this.#r.observe(this.canvas.parentNode);
      }
    }
    this.#o = new IntersectionObserver(this.#u.bind(this), {
      root: null,
      rootMargin: '0px',
      threshold: 0
    });
    this.#o.observe(this.canvas);
    document.addEventListener('visibilitychange', this.#v.bind(this));
  }
  #y() {
    window.removeEventListener('resize', this.#f.bind(this));
    this.#r?.disconnect();
    this.#o?.disconnect();
    document.removeEventListener('visibilitychange', this.#v.bind(this));
  }
  #u(e1: any) {
    this.#s = e1[0].isIntersecting;
    this.#s ? this.#w() : this.#z();
  }
  #v() {
    if (this.#s) {
      document.hidden ? this.#z() : this.#w();
    }
  }
  #f() {
    if (this.#a) clearTimeout(this.#a);
    this.#a = setTimeout(this.resize.bind(this), 100);
  }
  resize() {
    let e1: number, t1: number;
    if (this.#e.size instanceof Object) {
      e1 = this.#e.size.width;
      t1 = this.#e.size.height;
    } else if (this.#e.size === 'parent' && this.canvas.parentNode) {
      e1 = this.canvas.parentNode.offsetWidth;
      t1 = this.canvas.parentNode.offsetHeight;
    } else {
      e1 = window.innerWidth;
      t1 = window.innerHeight;
    }
    this.size.width = e1;
    this.size.height = t1;
    this.size.ratio = e1 / t1;
    this.#x();
    this.#b();
    this.onAfterResize(this.size);
  }
  #x() {
    this.camera.aspect = this.size.width / this.size.height;
    if (this.camera.isPerspectiveCamera && this.cameraFov) {
      if (this.cameraMinAspect && this.camera.aspect < this.cameraMinAspect) {
        this.#A(this.cameraMinAspect);
      } else if (this.cameraMaxAspect && this.camera.aspect > this.cameraMaxAspect) {
        this.#A(this.cameraMaxAspect);
      } else {
        this.camera.fov = this.cameraFov;
      }
    }
    this.camera.updateProjectionMatrix();
    this.updateWorldSize();
  }
  #A(e1: number) {
    const t1 = Math.tan(o.degToRad(this.cameraFov / 2)) / (this.camera.aspect / e1);
    this.camera.fov = 2 * o.radToDeg(Math.atan(t1));
  }
  updateWorldSize() {
    if (this.camera.isPerspectiveCamera) {
      const e1 = (this.camera.fov * Math.PI) / 180;
      this.size.wHeight = 2 * Math.tan(e1 / 2) * this.camera.position.length();
      this.size.wWidth = this.size.wHeight * this.camera.aspect;
    } else if (this.camera.isOrthographicCamera) {
      this.size.wHeight = this.camera.top - this.camera.bottom;
      this.size.wWidth = this.camera.right - this.camera.left;
    }
  }
  #b() {
    this.renderer.setSize(this.size.width, this.size.height);
    this.#t?.setSize(this.size.width, this.size.height);
    let e1 = window.devicePixelRatio;
    if (this.maxPixelRatio && e1 > this.maxPixelRatio) {
      e1 = this.maxPixelRatio;
    } else if (this.minPixelRatio && e1 < this.minPixelRatio) {
      e1 = this.minPixelRatio;
    }
    this.renderer.setPixelRatio(e1);
    this.size.pixelRatio = e1;
  }
  get postprocessing() {
    return this.#t;
  }
  set postprocessing(e1: any) {
    this.#t = e1;
    this.render = e1.render.bind(e1);
  }
  #w() {
    if (this.#n) return;
    const animate = () => {
      this.#l = requestAnimationFrame(animate);
      this.#h.delta = this.#c.getDelta();
      this.#h.elapsed += this.#h.delta;
      this.onBeforeRender(this.#h);
      this.render();
      this.onAfterRender(this.#h);
    };
    this.#n = true;
    this.#c.start();
    animate();
  }
  #z() {
    if (this.#n) {
      cancelAnimationFrame(this.#l);
      this.#n = false;
      this.#c.stop();
    }
  }
  #i() {
    this.renderer.render(this.scene, this.camera);
  }
  clear() {
    this.scene.traverse((e1: any) => {
      if (e1.isMesh && typeof e1.material === 'object' && e1.material !== null) {
        Object.keys(e1.material).forEach((t1: string) => {
          const i1 = e1.material[t1];
          if (i1 !== null && typeof i1 === 'object' && typeof i1.dispose === 'function') {
            i1.dispose();
          }
        });
        e1.material.dispose();
        e1.geometry.dispose();
      }
    });
    this.scene.clear();
  }
  dispose() {
    this.#y();
    this.#z();
    this.clear();
    this.#t?.dispose();
    this.renderer.dispose();
    this.renderer.forceContextLoss();
    this.isDisposed = true;
  }
}

const b = new Map(),
  A = new r();
let R = false;
function S(e1: any) {
  const t1: any = {
    position: new r(),
    nPosition: new r(),
    hover: false,
    touching: false,
    onEnter() {},
    onMove() {},
    onClick() {},
    onLeave() {},
    ...e1
  };
  (function (e2: any, t2: any) {
    if (!b.has(e2)) {
      b.set(e2, t2);
      if (!R) {
        document.body.addEventListener('pointermove', M);
        document.body.addEventListener('pointerleave', L);
        document.body.addEventListener('click', C);

        document.body.addEventListener('touchstart', TouchStart, { passive: false });
        document.body.addEventListener('touchmove', TouchMove, { passive: false });
        document.body.addEventListener('touchend', TouchEnd, { passive: false });
        document.body.addEventListener('touchcancel', TouchEnd, { passive: false });

        R = true;
      }
    }
  })(e1.domElement, t1);
  t1.dispose = () => {
    const t2 = e1.domElement;
    b.delete(t2);
    if (b.size === 0) {
      document.body.removeEventListener('pointermove', M);
      document.body.removeEventListener('pointerleave', L);
      document.body.removeEventListener('click', C);

      document.body.removeEventListener('touchstart', TouchStart);
      document.body.removeEventListener('touchmove', TouchMove);
      document.body.removeEventListener('touchend', TouchEnd);
      document.body.removeEventListener('touchcancel', TouchEnd);

      R = false;
    }
  };
  return t1;
}

function M(e1: any) {
  A.x = e1.clientX;
  A.y = e1.clientY;
  processInteraction();
}

function processInteraction() {
  for (const [elem, t1] of b) {
    const i1 = elem.getBoundingClientRect();
    if (D(i1)) {
      P(t1, i1);
      if (!t1.hover) {
        t1.hover = true;
        t1.onEnter(t1);
      }
      t1.onMove(t1);
    } else if (t1.hover && !t1.touching) {
      t1.hover = false;
      t1.onLeave(t1);
    }
  }
}

function C(e1: any) {
  A.x = e1.clientX;
  A.y = e1.clientY;
  for (const [elem, t1] of b) {
    const i1 = elem.getBoundingClientRect();
    P(t1, i1);
    if (D(i1)) t1.onClick(t1);
  }
}

function L() {
  for (const t1 of b.values()) {
    if (t1.hover) {
      t1.hover = false;
      t1.onLeave(t1);
    }
  }
}

function TouchStart(e1: any) {
  if (e1.touches.length > 0) {
    e1.preventDefault();
    A.x = e1.touches[0].clientX;
    A.y = e1.touches[0].clientY;

    for (const [elem, t1] of b) {
      const rect = elem.getBoundingClientRect();
      if (D(rect)) {
        t1.touching = true;
        P(t1, rect);
        if (!t1.hover) {
          t1.hover = true;
          t1.onEnter(t1);
        }
        t1.onMove(t1);
      }
    }
  }
}

function TouchMove(e1: any) {
  if (e1.touches.length > 0) {
    e1.preventDefault();
    A.x = e1.touches[0].clientX;
    A.y = e1.touches[0].clientY;

    for (const [elem, t1] of b) {
      const rect = elem.getBoundingClientRect();
      P(t1, rect);

      if (D(rect)) {
        if (!t1.hover) {
          t1.hover = true;
          t1.touching = true;
          t1.onEnter(t1);
        }
        t1.onMove(t1);
      } else if (t1.hover && t1.touching) {
        t1.onMove(t1);
      }
    }
  }
}

function TouchEnd() {
  for (const [, t1] of b) {
    if (t1.touching) {
      t1.touching = false;
      if (t1.hover) {
        t1.hover = false;
        t1.onLeave(t1);
      }
    }
  }
}

function P(e1: any, t1: any) {
  const { position: i1, nPosition: s1 } = e1;
  i1.x = A.x - t1.left;
  i1.y = A.y - t1.top;
  s1.x = (i1.x / t1.width) * 2 - 1;
  s1.y = (-i1.y / t1.height) * 2 + 1;
}
function D(e1: any) {
  const { x: t1, y: i1 } = A;
  const { left: s1, top: n1, width: o1, height: r1 } = e1;
  return t1 >= s1 && t1 <= s1 + o1 && i1 >= n1 && i1 <= n1 + r1;
}

const { randFloat: k, randFloatSpread: E } = o;
const F = new a();
const I = new a();
const O = new a();
const V = new a();
const B = new a();
const N = new a();
const _ = new a();
const j = new a();
const H = new a();
const T = new a();

class W {
  config: any;
  positionData: Float32Array;
  velocityData: Float32Array;
  sizeData: Float32Array;
  center: any;
  constructor(e1: any) {
    this.config = e1;
    this.positionData = new Float32Array(3 * e1.count).fill(0);
    this.velocityData = new Float32Array(3 * e1.count).fill(0);
    this.sizeData = new Float32Array(e1.count).fill(1);
    this.center = new a();
    this.#R();
    this.setSizes();
  }
  #R() {
    const { config: e1, positionData: t1 } = this;
    this.center.toArray(t1, 0);
    for (let i1 = 1; i1 < e1.count; i1++) {
      const s1 = 3 * i1;
      t1[s1] = E(2 * e1.maxX);
      t1[s1 + 1] = E(2 * e1.maxY);
      t1[s1 + 2] = E(2 * e1.maxZ);
    }
  }
  setSizes() {
    const { config: e1, sizeData: t1 } = this;
    t1[0] = e1.size0;
    for (let i1 = 1; i1 < e1.count; i1++) {
      t1[i1] = k(e1.minSize, e1.maxSize);
    }
  }
  update(e1: any) {
    const { config: t1, center: i1, positionData: s1, sizeData: n1, velocityData: o1 } = this;
    let r1 = 0;
    if (t1.controlSphere0) {
      r1 = 1;
      F.fromArray(s1, 0);
      F.lerp(i1, 0.1).toArray(s1, 0);
      V.set(0, 0, 0).toArray(o1, 0);
    }
    for (let idx = r1; idx < t1.count; idx++) {
      const base = 3 * idx;
      I.fromArray(s1, base);
      B.fromArray(o1, base);
      B.y -= e1.delta * t1.gravity * n1[idx];
      B.multiplyScalar(t1.friction);
      B.clampLength(0, t1.maxVelocity);
      I.add(B);
      I.toArray(s1, base);
      B.toArray(o1, base);
    }
    for (let idx = r1; idx < t1.count; idx++) {
      const base = 3 * idx;
      I.fromArray(s1, base);
      B.fromArray(o1, base);
      const radius = n1[idx];
      for (let jdx = idx + 1; jdx < t1.count; jdx++) {
        const otherBase = 3 * jdx;
        O.fromArray(s1, otherBase);
        N.fromArray(o1, otherBase);
        const otherRadius = n1[jdx];
        _.copy(O).sub(I);
        const dist = _.length();
        const sumRadius = radius + otherRadius;
        if (dist < sumRadius) {
          const overlap = sumRadius - dist;
          j.copy(_)
            .normalize()
            .multiplyScalar(0.5 * overlap);
          H.copy(j).multiplyScalar(Math.max(B.length(), 1));
          T.copy(j).multiplyScalar(Math.max(N.length(), 1));
          I.sub(j);
          B.sub(H);
          I.toArray(s1, base);
          B.toArray(o1, base);
          O.add(j);
          N.add(T);
          O.toArray(s1, otherBase);
          N.toArray(o1, otherBase);
        }
      }
      if (t1.controlSphere0) {
        _.copy(F).sub(I);
        const dist = _.length();
        const sumRadius0 = radius + n1[0];
        if (dist < sumRadius0) {
          const diff = sumRadius0 - dist;
          j.copy(_.normalize()).multiplyScalar(diff);
          H.copy(j).multiplyScalar(Math.max(B.length(), 2));
          I.sub(j);
          B.sub(H);
        }
      }
      if (Math.abs(I.x) + radius > t1.maxX) {
        I.x = Math.sign(I.x) * (t1.maxX - radius);
        B.x = -B.x * t1.wallBounce;
      }
      if (t1.gravity === 0) {
        if (Math.abs(I.y) + radius > t1.maxY) {
          I.y = Math.sign(I.y) * (t1.maxY - radius);
          B.y = -B.y * t1.wallBounce;
        }
      } else if (I.y - radius < -t1.maxY) {
        I.y = -t1.maxY + radius;
        B.y = -B.y * t1.wallBounce;
      }
      const maxBoundary = Math.max(t1.maxZ, t1.maxSize);
      if (Math.abs(I.z) + radius > maxBoundary) {
        I.z = Math.sign(I.z) * (t1.maxZ - radius);
        B.z = -B.z * t1.wallBounce;
      }
      I.toArray(s1, base);
      B.toArray(o1, base);
    }
  }
}

class Y extends c {
  uniforms: any;
  onBeforeCompile2: any;
  constructor(e1: any) {
    super(e1);
    this.uniforms = {
      thicknessDistortion: { value: 0.1 },
      thicknessAmbient: { value: 0 },
      thicknessAttenuation: { value: 0.1 },
      thicknessPower: { value: 2 },
      thicknessScale: { value: 10 }
    };
    (this as any).defines.USE_UV = '';
    this.onBeforeCompile = (e2: any) => {
      Object.assign(e2.uniforms, this.uniforms);
      e2.fragmentShader =
        '\n        uniform float thicknessPower;\n        uniform float thicknessScale;\n        uniform float thicknessDistortion;\n        uniform float thicknessAmbient;\n        uniform float thicknessAttenuation;\n      ' +
        e2.fragmentShader;
      e2.fragmentShader = e2.fragmentShader.replace(
        'void main() {',
        '\n        void RE_Direct_Scattering(const in IncidentLight directLight, const in vec2 uv, const in vec3 geometryPosition, const in vec3 geometryNormal, const in vec3 geometryViewDir, const in vec3 geometryClearcoatNormal, inout ReflectedLight reflectedLight) {\n          vec3 scatteringHalf = normalize(directLight.direction + (geometryNormal * thicknessDistortion));\n          float scatteringDot = pow(saturate(dot(geometryViewDir, -scatteringHalf)), thicknessPower) * thicknessScale;\n          #ifdef USE_COLOR\n            vec3 scatteringIllu = (scatteringDot + thicknessAmbient) * vColor;\n          #else\n            vec3 scatteringIllu = (scatteringDot + thicknessAmbient) * diffuse;\n          #endif\n          reflectedLight.directDiffuse += scatteringIllu * thicknessAttenuation * directLight.color;\n        }\n\n        void main() {\n      '
      );
      const t2 = h.lights_fragment_begin.replaceAll(
        'RE_Direct( directLight, geometryPosition, geometryNormal, geometryViewDir, geometryClearcoatNormal, material, reflectedLight );',
        '\n          RE_Direct( directLight, geometryPosition, geometryNormal, geometryViewDir, geometryClearcoatNormal, material, reflectedLight );\n          RE_Direct_Scattering(directLight, vUv, geometryPosition, geometryNormal, geometryViewDir, geometryClearcoatNormal, reflectedLight);\n        '
      );
      e2.fragmentShader = e2.fragmentShader.replace('#include <lights_fragment_begin>', t2);
      if (this.onBeforeCompile2) this.onBeforeCompile2(e2);
    };
  }
}

const X: any = {
  count: 200,
  colors: [0, 0, 0],
  ambientColor: 16777215,
  ambientIntensity: 1,
  lightIntensity: 200,
  materialParams: {
    metalness: 0.5,
    roughness: 0.5,
    clearcoat: 1,
    clearcoatRoughness: 0.15
  },
  minSize: 0.5,
  maxSize: 1,
  size0: 1,
  gravity: 0.5,
  friction: 0.9975,
  wallBounce: 0.95,
  maxVelocity: 0.15,
  maxX: 5,
  maxY: 5,
  maxZ: 2,
  controlSphere0: false,
  followCursor: true
};

const U = new m();

class Z extends d {
  config: any;
  physics: any;
  ambientLight: any;
  light: any;
  constructor(e1: any, t1: any = {}) {
    const i1 = { ...X, ...t1 };
    const s1 = new z();
    const n1 = new p(e1, 0.04).fromScene(s1).texture;
    const o1 = new g();
    const r1 = new Y({ envMap: n1, ...i1.materialParams });
    r1.envMapRotation.x = -Math.PI / 2;
    super(o1, r1, i1.count);
    this.config = i1;
    this.physics = new W(i1);
    this.#S();
    this.setColors(i1.colors);
  }
  #S() {
    this.ambientLight = new f(this.config.ambientColor, this.config.ambientIntensity);
    this.add(this.ambientLight);
    this.light = new u(this.config.colors[0], this.config.lightIntensity);
    this.add(this.light);
  }
  setColors(e1: any) {
    if (Array.isArray(e1) && e1.length > 1) {
      const t1 = (function (e2: any) {
        let t2: any, i2: any;
        function setColors(e3: any) {
          t2 = e3;
          i2 = [];
          t2.forEach((col: any) => {
            i2.push(new l(col));
          });
        }
        setColors(e2);
        return {
          setColors,
          getColorAt: function (ratio: number, out = new l()) {
            const scaled = Math.max(0, Math.min(1, ratio)) * (t2.length - 1);
            const idx = Math.floor(scaled);
            const start = i2[idx];
            if (idx >= t2.length - 1) return start.clone();
            const alpha = scaled - idx;
            const end = i2[idx + 1];
            out.r = start.r + alpha * (end.r - start.r);
            out.g = start.g + alpha * (end.g - start.g);
            out.b = start.b + alpha * (end.b - start.b);
            return out;
          }
        };
      })(e1);
      for (let idx = 0; idx < this.count; idx++) {
        this.setColorAt(idx, t1.getColorAt(idx / this.count));
        if (idx === 0) {
          this.light.color.copy(t1.getColorAt(idx / this.count));
        }
      }
      (this as any).instanceColor.needsUpdate = true;
    }
  }
  update(e1: any) {
    this.physics.update(e1);
    for (let idx = 0; idx < this.count; idx++) {
      U.position.fromArray(this.physics.positionData, 3 * idx);
      if (idx === 0 && this.config.followCursor === false) {
        U.scale.setScalar(0);
      } else {
        U.scale.setScalar(this.physics.sizeData[idx]);
      }
      U.updateMatrix();
      this.setMatrixAt(idx, U.matrix);
      if (idx === 0) this.light.position.copy(U.position);
    }
    (this as any).instanceMatrix.needsUpdate = true;
  }
}

function createBallpit(e1: any, t1: any = {}) {
  const i1 = new x({
    canvas: e1,
    size: 'parent',
    rendererOptions: { antialias: true, alpha: true }
  });
  let s1: any;
  i1.renderer.toneMapping = v;
  i1.camera.position.set(0, 0, 20);
  i1.camera.lookAt(0, 0, 0);
  i1.cameraMaxAspect = 1.5;
  i1.resize();
  initialize(t1);
  const n1 = new y();
  const o1 = new w(new a(0, 0, 1), 0);
  const r1 = new a();
  let c1 = false;

  e1.style.touchAction = 'none';
  e1.style.userSelect = 'none';
  e1.style.webkitUserSelect = 'none';

  const h1 = S({
    domElement: e1,
    onMove() {
      n1.setFromCamera(h1.nPosition, i1.camera);
      i1.camera.getWorldDirection(o1.normal);
      n1.ray.intersectPlane(o1, r1);
      s1.physics.center.copy(r1);
      s1.config.controlSphere0 = true;
    },
    onLeave() {
      s1.config.controlSphere0 = false;
    }
  });
  function initialize(e2: any) {
    if (s1) {
      i1.clear();
      i1.scene.remove(s1);
    }
    s1 = new Z(i1.renderer, e2);
    i1.scene.add(s1);
  }
  i1.onBeforeRender = (e2: any) => {
    if (!c1) s1.update(e2);
  };
  i1.onAfterResize = (e2: any) => {
    s1.config.maxX = e2.wWidth / 2;
    s1.config.maxY = e2.wHeight / 2;
  };
  return {
    three: i1,
    get spheres() {
      return s1;
    },
    setCount(e2: number) {
      initialize({ ...s1.config, count: e2 });
    },
    togglePause() {
      c1 = !c1;
    },
    dispose() {
      h1.dispose();
      i1.dispose();
    }
  };
}

const Ballpit = ({ className = '', followCursor = true, colors: inputColors, ...props }: BallpitProps) => {
  const canvasRef = useRef<HTMLCanvasElement>(null);
  const spheresInstanceRef = useRef<any>(null);

  // propsRef pattern (see WP_hubbee/src/backgrounds/AUDIT.md): we keep a fresh
  // copy of the latest props on every render. The setup effect below runs
  // only once; live config tweaks propagate through `propsRef.current` to
  // the spheres `config` object every animation frame.
  const propsRef = useRef({ followCursor, inputColors, ...props });
  propsRef.current = { followCursor, inputColors, ...props };

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    // ── one-shot setup ───────────────────────────────────────────────
    // Wait a frame for layout to compute canvas dimensions. WebGLRenderer
    // needs a non-zero size to get a GL context.
    const rafId = requestAnimationFrame(() => {
      if (!canvas.clientWidth || !canvas.clientHeight) return;
      const initial = propsRef.current;
      const initialColors =
        initial.inputColors !== undefined ? normalizeColorArray(initial.inputColors) : undefined;
      spheresInstanceRef.current = createBallpit(canvas, {
        followCursor: initial.followCursor,
        ...initial,
        ...(initialColors ? { colors: initialColors } : {}),
      });
    });

    // ── live prop sync ───────────────────────────────────────────────
    // Most ballpit knobs map to fields on `instance.spheres.config`. We poll
    // those at a low cadence (twice per second) so slider drags reflect in
    // the live preview without a remount. Color-array changes go through
    // `setColors`, which rebuilds the InstancedMesh color buffer.
    let lastColorsKey = JSON.stringify(propsRef.current.inputColors ?? null);
    const syncId = window.setInterval(() => {
      const inst = spheresInstanceRef.current;
      if (!inst?.spheres?.config) return;
      const cfg = inst.spheres.config;
      const p = propsRef.current;
      if (typeof p.gravity === 'number') cfg.gravity = p.gravity;
      if (typeof p.friction === 'number') cfg.friction = p.friction;
      if (typeof p.wallBounce === 'number') cfg.wallBounce = p.wallBounce;
      if (typeof p.maxVelocity === 'number') cfg.maxVelocity = p.maxVelocity;
      if (typeof p.minSize === 'number') cfg.minSize = p.minSize;
      if (typeof p.maxSize === 'number') cfg.maxSize = p.maxSize;
      if (typeof p.size0 === 'number') cfg.size0 = p.size0;
      if (typeof p.lightIntensity === 'number' && inst.spheres.light) {
        inst.spheres.light.intensity = p.lightIntensity;
      }
      if (typeof p.ambientIntensity === 'number' && inst.spheres.ambientLight) {
        inst.spheres.ambientLight.intensity = p.ambientIntensity;
      }
      cfg.followCursor = p.followCursor;

      const colorsKey = JSON.stringify(p.inputColors ?? null);
      if (colorsKey !== lastColorsKey) {
        lastColorsKey = colorsKey;
        const normalised =
          p.inputColors !== undefined ? normalizeColorArray(p.inputColors) : undefined;
        if (normalised && Array.isArray(normalised)) inst.spheres.setColors(normalised);
      }
    }, 500);

    // ── context-loss recovery ───────────────────────────────────────
    const onContextLost = (e: Event) => {
      e.preventDefault();
    };
    canvas.addEventListener('webglcontextlost', onContextLost);

    return () => {
      cancelAnimationFrame(rafId);
      window.clearInterval(syncId);
      canvas.removeEventListener('webglcontextlost', onContextLost);
      if (spheresInstanceRef.current) {
        try {
          spheresInstanceRef.current.dispose();
        } catch {
          /* swallow — dispose is best-effort */
        }
        spheresInstanceRef.current = null;
      }
      // NOTE: do NOT call WEBGL_lose_context.loseContext() on this canvas.
      // The <canvas> element is React-managed (lives in JSX), so React
      // StrictMode may re-mount the effect with the same DOM node. Forcing
      // context-loss here would leave that canvas with a permanently dead
      // GL context, and the next mount's `new WebGLRenderer({ canvas })`
      // crashes inside `getMaxPrecision` because `getShaderPrecisionFormat`
      // returns null on a lost context. `instance.dispose()` already
      // releases three.js GPU resources internally — that is sufficient.
    };
  }, []);

  return <canvas className={className} ref={canvasRef} style={{ width: '100%', height: '100%' }} />;
};

export default Ballpit;
