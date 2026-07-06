import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { readdirSync, statSync, existsSync } from 'fs';
import { resolve } from 'path';

/**
 * Background Effects Build Config
 *
 * Three build targets controlled via BUILD_TARGET env var:
 *   bg-vendor  — bundles React into a global (vendor-react.min.js)
 *   bg-runtime — builds the hydrator runtime (runtime.min.js), React external
 *   bg-effects — builds all effect chunks (bg-*.min.js), React external
 *
 * Usage:
 *   BUILD_TARGET=bg-vendor  vite build --config vite.backgrounds.config.ts
 *   BUILD_TARGET=bg-runtime vite build --config vite.backgrounds.config.ts
 *   BUILD_TARGET=bg-effects vite build --config vite.backgrounds.config.ts
 */

const buildTarget = process.env.BUILD_TARGET || 'bg-vendor';

// ── Auto-discover effect entries ────────────────────────────────────
const effectsDir = resolve(__dirname, 'src/backgrounds/effects');
const effectEntries: Record<string, string> = {};

if (existsSync(effectsDir)) {
  readdirSync(effectsDir).forEach((name) => {
    const dir = resolve(effectsDir, name);
    if (statSync(dir).isDirectory()) {
      // Support both .tsx and .ts entry points
      for (const ext of ['index.tsx', 'index.ts']) {
        const entry = resolve(dir, ext);
        if (existsSync(entry)) {
          effectEntries[`bg-${name}`] = entry;
          break;
        }
      }
    }
  });
}

// ── React externals (for runtime + effects) ─────────────────────────
const reactExternal = ['react', 'react-dom', 'react-dom/client', 'react/jsx-runtime'];
const reactGlobals: Record<string, string> = {
  react: 'React',
  'react-dom': 'ReactDOM',
  'react-dom/client': 'ReactDOM',
  'react/jsx-runtime': 'ReactJSXRuntime',
};

// ── Three / R3F externals (for effects only — vendor-three + vendor-r3f
//    expose these as globals so each bg-*.min.js can rely on a single
//    shared instance instead of bundling its own copy)
const threeExternal = [
  'three',
  '@react-three/fiber',
  '@react-three/drei',
  '@react-three/postprocessing',
];
const threeGlobals: Record<string, string> = {
  three: 'THREE',
  '@react-three/fiber': 'HubbeeR3F.fiber',
  '@react-three/drei': 'HubbeeR3F.drei',
  '@react-three/postprocessing': 'HubbeeR3F.postprocessing',
};

const effectsExternal = [...reactExternal, ...threeExternal];
const effectsGlobals = { ...reactGlobals, ...threeGlobals };

// ── Build configs per target ────────────────────────────────────────

const configs: Record<string, ReturnType<typeof defineConfig>> = {
  'bg-vendor': defineConfig({
    plugins: [react()],
    define: {
      'process.env.NODE_ENV': JSON.stringify('production'),
      'process.env': JSON.stringify({}),
    },
    build: {
      outDir: 'assets/js/backgrounds',
      emptyOutDir: true,
      lib: {
        entry: resolve(__dirname, 'src/backgrounds/_vendor/react.ts'),
        name: 'HubbeeBgVendor',
        fileName: () => 'vendor-react.min.js',
        formats: ['iife'] as const,
      },
      minify: 'terser',
      sourcemap: false,
      rollupOptions: {
        output: {
          inlineDynamicImports: true,
        },
      },
    },
  }),

  'bg-three': defineConfig({
    plugins: [],
    define: {
      'process.env.NODE_ENV': JSON.stringify('production'),
      'process.env': JSON.stringify({}),
    },
    build: {
      outDir: 'assets/js/backgrounds',
      emptyOutDir: false,
      lib: {
        entry: resolve(__dirname, 'src/backgrounds/_vendor/three.ts'),
        name: 'HubbeeThree',
        fileName: () => 'vendor-three.min.js',
        formats: ['iife'] as const,
      },
      minify: 'terser',
      sourcemap: false,
      rollupOptions: {
        output: {
          inlineDynamicImports: true,
        },
      },
    },
  }),

  'bg-r3f': defineConfig({
    plugins: [react()],
    define: {
      'process.env.NODE_ENV': JSON.stringify('production'),
      'process.env': JSON.stringify({}),
    },
    build: {
      outDir: 'assets/js/backgrounds',
      emptyOutDir: false,
      lib: {
        entry: resolve(__dirname, 'src/backgrounds/_vendor/r3f.ts'),
        name: 'HubbeeR3F',
        fileName: () => 'vendor-r3f.min.js',
        formats: ['iife'] as const,
      },
      minify: 'terser',
      sourcemap: false,
      rollupOptions: {
        external: [...reactExternal, 'three'],
        output: {
          globals: { ...reactGlobals, three: 'THREE' },
          inlineDynamicImports: true,
        },
      },
    },
  }),

  'bg-runtime': defineConfig({
    plugins: [],
    define: {
      'process.env.NODE_ENV': JSON.stringify('production'),
      'process.env': JSON.stringify({}),
    },
    build: {
      outDir: 'assets/js/backgrounds',
      emptyOutDir: false,
      lib: {
        entry: resolve(__dirname, 'src/backgrounds/_runtime/index.ts'),
        name: 'HubbeeBgRuntime',
        fileName: () => 'runtime.min.js',
        formats: ['iife'] as const,
      },
      minify: 'terser',
      sourcemap: false,
      rollupOptions: {
        external: reactExternal,
        output: {
          globals: reactGlobals,
          inlineDynamicImports: true,
        },
      },
    },
  }),

  // bg-effects builds a SINGLE effect at a time (IIFE doesn't support multi-entry)
  // Use BG_EFFECT env var to pick which one: BUILD_TARGET=bg-effects BG_EFFECT=aurora
  'bg-effects': (() => {
    const singleEffect = process.env.BG_EFFECT;
    if (singleEffect && effectEntries[`bg-${singleEffect}`]) {
      return defineConfig({
        plugins: [react()],
        define: {
          'process.env.NODE_ENV': JSON.stringify('production'),
          'process.env': JSON.stringify({}),
        },
        build: {
          outDir: 'assets/js/backgrounds',
          emptyOutDir: false,
          lib: {
            entry: effectEntries[`bg-${singleEffect}`],
            name: `HubbeeBg_${singleEffect.replace(/-/g, '_')}`,
            fileName: () => `bg-${singleEffect}.min.js`,
            formats: ['iife'] as const,
          },
          rollupOptions: {
            external: effectsExternal,
            output: {
              globals: effectsGlobals,
              inlineDynamicImports: true,
            },
          },
          minify: 'terser',
          sourcemap: false,
        },
      });
    }
    // When BG_EFFECT is not set, build ALL effects sequentially (handled by shell script)
    // This config is a no-op fallback
    console.log('Available effects:', Object.keys(effectEntries).map(k => k.replace('bg-', '')).join(', '));
    console.log('Set BG_EFFECT=<name> to build a specific effect, or use npm run build:bg-effects to build all.');
    return defineConfig({ build: { outDir: 'assets/js/backgrounds', emptyOutDir: false, rollupOptions: { input: {} } } });
  })(),
};

export default configs[buildTarget] || configs['bg-vendor'];
