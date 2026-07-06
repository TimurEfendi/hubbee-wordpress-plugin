import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import cssInjectedByJsPlugin from 'vite-plugin-css-injected-by-js';
import { readdirSync, statSync, existsSync } from 'fs';
import { resolve } from 'path';

/**
 * Text Effects Build Config
 *
 * Three build targets controlled via BUILD_TARGET env var:
 *   tx-vendor  — bundles React into a global (vendor-react.min.js)
 *   tx-runtime — builds the hydrator runtime (textfx-runtime.min.js), React external
 *   tx-effects — builds all effect chunks (tx-*.min.js), React external
 *
 * Usage:
 *   BUILD_TARGET=tx-vendor  vite build --config vite.textfx.config.ts
 *   BUILD_TARGET=tx-runtime vite build --config vite.textfx.config.ts
 *   BUILD_TARGET=tx-effects TX_EFFECT=text-pressure vite build --config vite.textfx.config.ts
 */

const buildTarget = process.env.BUILD_TARGET || 'tx-runtime';

// ── Auto-discover effect entries ────────────────────────────────────
const effectsDir = resolve(__dirname, 'src/textfx/effects');
const effectEntries: Record<string, string> = {};

if (existsSync(effectsDir)) {
  readdirSync(effectsDir).forEach((name) => {
    const dir = resolve(effectsDir, name);
    if (statSync(dir).isDirectory()) {
      for (const ext of ['index.tsx', 'index.ts']) {
        const entry = resolve(dir, ext);
        if (existsSync(entry)) {
          effectEntries[`tx-${name}`] = entry;
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

// ── Build configs per target ────────────────────────────────────────

const configs: Record<string, ReturnType<typeof defineConfig>> = {
  // Tiny always-present frontend bootstrap (no React). Observes data-bz-*
  // containers and lazy-loads each dependency chain on viewport intersection.
  'loader': defineConfig({
    plugins: [],
    define: {
      'process.env.NODE_ENV': JSON.stringify('production'),
      'process.env': JSON.stringify({}),
    },
    build: {
      outDir: 'assets/js',
      emptyOutDir: false,
      lib: {
        entry: resolve(__dirname, 'src/loader/index.ts'),
        name: 'HubbeeAssetLoader',
        fileName: () => 'hubbee-asset-loader.min.js',
        formats: ['iife'] as const,
      },
      minify: 'terser',
      sourcemap: false,
    },
  }),

  // tx-vendor target retired — react vendor lives at
  // assets/js/backgrounds/vendor-react.min.js and is shared across bg/el/tx.
  'tx-runtime': defineConfig({
    plugins: [],
    define: {
      'process.env.NODE_ENV': JSON.stringify('production'),
      'process.env': JSON.stringify({}),
    },
    build: {
      outDir: 'assets/js/textfx',
      emptyOutDir: false,
      lib: {
        entry: resolve(__dirname, 'src/textfx/_runtime/index.ts'),
        name: 'HubbeeTxRuntime',
        fileName: () => 'textfx-runtime.min.js',
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

  'tx-effects': (() => {
    const singleEffect = process.env.TX_EFFECT;
    if (singleEffect && effectEntries[`tx-${singleEffect}`]) {
      return defineConfig({
        // cssInjectedByJsPlugin inlines each effect's imported CSS into the
        // chunk IIFE so it loads on the WP frontend (mirrors
        // vite.elements.config.ts). Without it, effect CSS (TrueFocus corners,
        // ScrollVelocity marquee, etc.) is emitted to an unused style.css and
        // never reaches WordPress.
        plugins: [react(), cssInjectedByJsPlugin({ topExecutionPriority: true })],
        define: {
          'process.env.NODE_ENV': JSON.stringify('production'),
          'process.env': JSON.stringify({}),
        },
        build: {
          outDir: 'assets/js/textfx',
          emptyOutDir: false,
          lib: {
            entry: effectEntries[`tx-${singleEffect}`],
            name: `HubbeeTx_${singleEffect.replace(/-/g, '_')}`,
            fileName: () => `tx-${singleEffect}.min.js`,
            formats: ['iife'] as const,
          },
          rollupOptions: {
            external: reactExternal,
            output: {
              globals: reactGlobals,
              inlineDynamicImports: true,
            },
          },
          minify: 'terser',
          sourcemap: false,
        },
      });
    }
    console.log('Available effects:', Object.keys(effectEntries).map(k => k.replace('tx-', '')).join(', '));
    console.log('Set TX_EFFECT=<name> to build a specific effect, or use npm run build:tx-effects to build all.');
    return defineConfig({ build: { outDir: 'assets/js/textfx', emptyOutDir: false, rollupOptions: { input: {} } } });
  })(),
};

export default configs[buildTarget] || configs['tx-runtime'];
