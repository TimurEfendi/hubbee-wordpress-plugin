# Hubbee — WordPress Plugin (source)

Human-readable source for the **Hubbee** WordPress plugin. Published so the
compiled JavaScript that ships in the plugin ZIP has openly available source, per
the [wordpress.org plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).

- Product: https://hubbee.io · Support & docs: https://hubbee.io/help
- License: **GPL-2.0-or-later**

## Layout
- `includes/` — PHP runtime: REST endpoints, the command agent, storage, Elementor and block integrations.
- `src/` — TypeScript source for the frontend runtimes (backgrounds, elements, text effects) + blocks. Compiled to `assets/js/**` with Vite.
- `assets/` — compiled runtimes and static assets that ship in the plugin.
- `readme.txt`, `hubbee.php`, `uninstall.php` — plugin metadata and lifecycle.

## Build
```
npm install
npm run build            # Vite: vite.elements/backgrounds/textfx.config.ts
composer install         # dev tooling (PHPUnit)
vendor/bin/phpunit
```

## Note
Hubbee is the WordPress-side agent of the Hubbee SaaS and requires a Hubbee
account. It makes no outbound calls until you connect a site from the dashboard.
