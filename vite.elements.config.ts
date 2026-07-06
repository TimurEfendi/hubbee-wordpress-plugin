import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import cssInjectedByJsPlugin from 'vite-plugin-css-injected-by-js';
import { readdirSync, statSync, existsSync } from 'fs';
import { resolve } from 'path';

/**
 * Element Library Build Config
 *
 * Three build targets controlled via BUILD_TARGET env var:
 *   el-vendor  — bundles React into a global (vendor-react.min.js)
 *   el-runtime — builds the hydrator runtime (element-runtime.min.js), React external
 *   el-effects — builds all element chunks (el-*.min.js), React external
 *
 * Usage:
 *   BUILD_TARGET=el-vendor  vite build --config vite.elements.config.ts
 *   BUILD_TARGET=el-runtime vite build --config vite.elements.config.ts
 *   BUILD_TARGET=el-effects vite build --config vite.elements.config.ts
 */

const buildTarget = process.env.BUILD_TARGET || 'el-runtime';

// ── Auto-discover effect entries ────────────────────────────────────
const effectsDir = resolve(__dirname, 'src/elements/effects');
const effectEntries: Record<string, string> = {};

if (existsSync(effectsDir)) {
  readdirSync(effectsDir).forEach((name) => {
    const dir = resolve(effectsDir, name);
    if (statSync(dir).isDirectory()) {
      // Support both .tsx and .ts entry points
      for (const ext of ['index.tsx', 'index.ts']) {
        const entry = resolve(dir, ext);
        if (existsSync(entry)) {
          effectEntries[`el-${name}`] = entry;
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
  // el-vendor target retired — react vendor lives at
  // assets/js/backgrounds/vendor-react.min.js and is shared across
  // bg/el/tx (see ContainerBackgroundExtension::enqueue_vendor_bundles).
  'el-runtime': defineConfig({
    plugins: [],
    define: {
      'process.env.NODE_ENV': JSON.stringify('production'),
      'process.env': JSON.stringify({}),
    },
    resolve: {
      alias: {
        // Shared TS modules from the SaaS repo. Used for the
        // section→vendor converter so editor + WP runtime read from
        // the same source.
        '@hubbee-shared': resolve(__dirname, '../src/lib'),
        // Editor-side renderer wrappers under SaaS `src/components/`.
        // Effect chunks import these directly so WP renders pixel-
        // identical to the configurator preview.
        '@hubbee-saas': resolve(__dirname, '../src'),
        '@': resolve(__dirname, '../src'),
      },
    },
    build: {
      outDir: 'assets/js/elements',
      emptyOutDir: false,
      lib: {
        entry: resolve(__dirname, 'src/elements/_runtime/index.ts'),
        name: 'HubbeeElRuntime',
        fileName: () => 'element-runtime.min.js',
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

  // el-effects builds a SINGLE element at a time (IIFE doesn't support multi-entry)
  // Use EL_EFFECT env var to pick which one: BUILD_TARGET=el-effects EL_EFFECT=counter
  'el-effects': (() => {
    const singleEffect = process.env.EL_EFFECT;
    if (singleEffect && effectEntries[`el-${singleEffect}`]) {
      return defineConfig({
        plugins: [
          react(),
          // Inline the per-effect CSS into the IIFE chunk. Without this,
          // Vite writes all element CSS to the same default style.css
          // filename, and the sequential build loop overwrites it — only
          // the last alphabetical element's CSS survives. Inlining makes
          // each chunk self-contained so CSS travels with the JS hash.
          cssInjectedByJsPlugin({ topExecutionPriority: true }),
        ],
        define: {
          'process.env.NODE_ENV': JSON.stringify('production'),
          'process.env': JSON.stringify({}),
        },
        resolve: {
          alias: {
            '@hubbee-shared': resolve(__dirname, '../src/lib'),
            // Editor-side renderer wrappers (src/components/*.tsx) are the
            // canonical renderers — effect chunks bundle them directly so
            // WP renders pixel-identical to the configurator preview.
            '@hubbee-saas': resolve(__dirname, '../src'),
            '@': resolve(__dirname, '../src'),
          },
        },
        build: {
          outDir: 'assets/js/elements',
          emptyOutDir: false,
          lib: {
            entry: effectEntries[`el-${singleEffect}`],
            name: `HubbeeEl_${singleEffect.replace(/-/g, '_')}`,
            fileName: () => `el-${singleEffect}.min.js`,
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
    // When EL_EFFECT is not set, build ALL elements sequentially (handled by shell script)
    // This config is a no-op fallback
    console.log('Available elements:', Object.keys(effectEntries).map(k => k.replace('el-', '')).join(', '));
    console.log('Set EL_EFFECT=<name> to build a specific element, or use npm run build:el-effects to build all.');
    return defineConfig({ build: { outDir: 'assets/js/elements', emptyOutDir: false, rollupOptions: { input: {} } } });
  })(),
};

export default configs[buildTarget] || configs['el-runtime'];
