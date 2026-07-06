/**
 * vendor-three entry — re-exports the entire three.js public API as a single
 * IIFE-friendly namespace and assigns it to `window.THREE`.
 *
 * Why the full re-export instead of a curated subset?
 *   `@react-three/fiber` (and drei + postprocessing) are built with `three`
 *   marked as external. At init time fiber's reconciler reaches into the
 *   THREE namespace for classes that vendor-components never touch directly
 *   (Object3D, Matrix4, Group, Layers, Raycaster, EventDispatcher,
 *   Quaternion, Euler, MathUtils, …). A curated `export { Mesh, Scene, … }`
 *   subset crashes fiber on boot with `Cannot read properties of undefined`.
 *   The full namespace export is the correct primitive — three.js itself is
 *   ~175 KB gzipped and that's the floor for a working r3f setup.
 *
 * Tree-shaking opportunity exists later by retiring r3f entirely (rewriting
 * Silk / Dither / Beams to vanilla three) — until then, full API is required.
 *
 * @package Hubbee\Backgrounds
 */

import * as THREE from 'three';

export * from 'three';

if (typeof window !== 'undefined') {
  (window as unknown as { THREE: typeof THREE }).THREE = THREE;
}

export default THREE;
