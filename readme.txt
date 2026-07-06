=== Hubbee ===
Contributors: 2brandsmedia
Tags: site management, content sync, multi-site, agency, elementor
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.0.6
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Centrally manage text tokens, components and visual assets across many WordPress sites. The companion plugin to the Hubbee SaaS dashboard.

== Description ==

If you operate more than a handful of WordPress sites — for clients, for franchises, for a portfolio — you know the cost of fragmentation. Thirty logins, thirty admin panels, every content change repeated thirty times. Updates take longer than the actual work.

**Hubbee** solves this by separating *content* from *site*. You edit text tokens, headings, asset configurations, page-level content blocks centrally in the Hubbee dashboard, and a single click pushes the change to every connected WordPress site. Each push is HMAC-SHA256-signed, versioned, and logged. If something goes wrong, roll back in one click.

The Hubbee plugin is the WordPress-side agent of this architecture. It exposes a small REST surface that accepts authenticated pushes from your Hubbee Cloud account, stores received tokens in your own WordPress database (`wp_bz_tokens`), renders them via Elementor Dynamic Tags, shortcodes, or blocks, and reports site health back so you can spot outages before your client picks up the phone.

= What Hubbee gives you =

* **Centralized text tokens** — edit once, push to many sites with full audit trail
* **Visual asset library** — backgrounds, animated elements, and text effects assigned per site, downloaded on-demand to your local uploads folder
* **Health monitoring** — heartbeat every five minutes, plugin and theme update inventory across every site, memory and disk warnings
* **Activity stream** — every push, every edit, every error logged and searchable
* **Multi-site, multi-locale** — per-locale token values fall back to a default, no template gymnastics
* **GPL v2 plugin, EU-hosted SaaS** — open and honest

= External services and dynamic chunks (REQUIRED disclosure for service-plugins) =

This plugin connects to the Hubbee SaaS to receive content and report site health. The following external services are used:

* **Hubbee Cloud** — managed backend hosted in the EU (Germany). Used for token-push, heartbeat, health-reports, content-events. All requests are HMAC-SHA256-signed with a per-site secret and validated against a 5-minute timestamp window. Full host details and data-processing terms: https://hubbee.io/legal/datenschutz
* **Hubbee API (Hetzner, Germany)** — `https://api.hubbee.io`. Lower-latency endpoint for European sites, activated when you set `define( 'HUBBEE_API_ENDPOINT', 'https://api.hubbee.io' );` in `wp-config.php`.
* **CORS allowlist** for inbound calls: `hubbee.io`, `www.hubbee.io`, `app.hubbee.io`, `api.hubbee.io`.

**Dynamic visual-effect chunks**: when you assign a background, element, or text-effect to a site in your Hubbee dashboard, the plugin downloads the corresponding compiled JavaScript chunk from your Hubbee Cloud account and stores it locally in `wp-content/uploads/hubbee/chunks/`. Each chunk is integrity-checked via SHA-256 hash before being written. The plugin ships with a fixed runtime + vendor library (~55 KB) that loads only the locally-stored chunks via `wp_enqueue_script` — no third-party CDN at runtime, no per-page-view external requests.

**What is sent to Hubbee Cloud automatically (when connected):**

* Heartbeat pings (no body, JWT-authenticated, default every 5 minutes)
* Optional health snapshots (WordPress version, PHP version, plugin version, token count)
* Optional deep health reports (memory and disk usage percentages, available plugin and theme update list, last error message)
* Token push receipts (per content update — confirms which tokens were applied)
* Optional visitor analytics — **off by default, opt-in only** (page path, a per-session hash, referrer domain, user agent); enable/disable from your dashboard

**Site management — only on a command you trigger from your Hubbee dashboard** (each request HMAC-SHA256 signed with your per-site secret):

* Install / activate / deactivate / update / delete plugins and themes (installs are restricted to the wordpress.org directory), and WordPress core updates
* Create / update / delete posts, pages, media and comments
* Return the site's plugin, theme and **user list** (id, username, email, role) — passwords and hashes are never sent

**What is NOT sent:**

* Passwords or password hashes — never, under any command
* Post/page content and comments are not sent on a schedule — only when a command you trigger reads them
* Nothing at all until you connect a site from the dashboard

Privacy policy: https://hubbee.io/legal/datenschutz
Terms of service: https://hubbee.io/legal/agb

The plugin requires an active Hubbee account at https://hubbee.io. A permanent free plan is available — no credit card required. The plugin makes no outbound calls until you connect a site from the dashboard.

== Installation ==

1. In WordPress Admin, go to **Plugins → Add New**, search for **Hubbee**, click **Install Now**, then **Activate**. (Alternative: download `hubbee.zip` from the Hubbee dashboard and upload via Plugins → Add New → Upload Plugin.)
2. In the Hubbee dashboard at https://app.hubbee.io, click **Add Site**, generate a one-time connection code, copy it.
3. Back in WordPress Admin, navigate to **Hubbee → Settings**, paste the connection code, click **Connect**.
4. Within seconds, the site appears in your Hubbee dashboard with online status and basic health information. Token-push, asset-assign, and health-monitoring are now active.

== Frequently Asked Questions ==

= Do I need a Hubbee account? =

Yes. Hubbee is a service-plugin — the WordPress side is the agent, the SaaS at https://hubbee.io is the dashboard. A permanent free plan is available; no credit card required.

= Why does the plugin use the `bz/v1` REST namespace and `BZ_*` PHP constants? =

Hubbee evolved from a previous product called "Brandzilla". The internal `bz_*` prefixes (database tables, options, action hooks, REST namespace, JavaScript hydration attributes) are kept for backwards-compatibility with existing installs — renaming them would force a destructive migration on every customer site. The plugin name and all user-facing surface are now fully "Hubbee".

= Can I disable heartbeats and health-reports? =

Yes. Add this snippet to a mu-plugin (`wp-content/mu-plugins/hubbee-no-telemetry.php`):

`<?php
add_filter( 'hubbee_heartbeat_enabled', '__return_false' );
add_filter( 'hubbee_health_report_enabled', '__return_false' );`

The plugin will then make no telemetry calls. SaaS-to-plugin pushes (which you triggered yourself from the dashboard) still work — those are not telemetry.

You can also disconnect the site entirely via **Hubbee → Settings → Disconnect**, which drops the per-site secret and halts all outbound calls.

= How is the plugin authenticated? =

* SaaS → Plugin (push, commands): HMAC-SHA256 signature over `timestamp.body`, plus `X-Hubbee-Site-Id` header, with a 5-minute timestamp window to prevent replays.
* Plugin → SaaS (heartbeat, health, push receipts): JWT signed with the same per-site secret.
* Initial enrollment: a one-time onboarding token issued by the dashboard, exchanged for the long-lived per-site secret.

= Why doesn't the plugin use WordPress nonces on its REST endpoints? =

The Hubbee REST endpoints (`/wp-json/bz/v1/*`) are server-to-server endpoints called by the Hubbee SaaS — not by browser users. Nonces are designed for browser-session callers; HMAC-signature with timestamp and a per-site secret is the industry-standard pattern for server-to-server authentication.

= How do the visual-effect chunks work? =

When you assign a background, element, or text-effect to a site in the Hubbee dashboard, the dashboard sends a push to the plugin with the corresponding chunk's URL and SHA-256 hash. The plugin downloads the chunk via `wp_remote_get`, verifies the hash, and stores the result in `wp-content/uploads/hubbee/chunks/`. Subsequent page renders enqueue the local file via `wp_enqueue_script` — no further external traffic. When you remove an assignment, the plugin deletes the chunk.

= Where is my data stored? =

In your own WordPress database. Tokens are stored in `wp_bz_tokens`, idempotency markers in `wp_bz_processed_requests`. The Hubbee SaaS holds the source-of-truth for content (so you can push to many sites), but each site keeps its own local copy. If you uninstall the plugin via WordPress's standard uninstall flow, all `bz_*` tables, options, transients, user-meta, and capabilities are removed cleanly — see `uninstall.php`.

= Does the plugin work with caching plugins like WP Rocket, LiteSpeed, or Cloudflare? =

Yes. After a content push, the plugin automatically clears its own internal manifest caches, but page-level caches and CDN caches are managed by the respective products and may need a manual purge to surface the new content. See https://hubbee.io/help for the full caching-compatibility checklist.

= A component is still visible on the frontend although I deleted it in the SaaS. What now? =

Run `wp hubbee cache clear --all`, then purge any page-level / CDN cache. The plugin's manifest tells it which components are still active; orphaned components are removed on the next manifest refresh. Full troubleshooting flow at https://hubbee.io/help/troubleshooting-plugin.

= Is Elementor required? =

No. Elementor integration is optional and only initialised when Elementor is active. Without Elementor, you still get the SaaS-controlled content tokens (renderable via shortcode `[bz_text key="hero_title"]` and a Gutenberg block), the asset library, and component-rendering — Elementor just adds Dynamic-Tag convenience for token rendering inside Elementor templates.

= Is the source code open? =

Yes, GPLv2-or-later. The distributed ZIP contains the compiled runtimes; the full human-readable source (including the TypeScript for the runtimes and blocks) with build instructions is published at https://github.com/TimurEfendi/hubbee-wordpress-plugin. Bug-tracker and contribution guidelines: https://hubbee.io/help.

= How do I uninstall cleanly? =

Standard WordPress flow: Plugins → Hubbee → Deactivate → Delete. The plugin's `uninstall.php` removes the two database tables, all `bz_*` options, all `_transient_bz_*` transients, all `bz_*` user-meta, the `bz_manage_settings` capability, and the uploads `.htaccess` CORS section. Nothing is left behind.

== Screenshots ==

1. The Hubbee dashboard with multiple connected WordPress sites — health status, last heartbeat, plugin update inventory at a glance.
2. Pushing a content token from the dashboard to multiple sites simultaneously, with HMAC-signed delivery and per-site result.
3. Activity stream with edit history, version diff, and one-click rollback to a previous value.
4. WordPress plugin settings page after connection — site_id, encrypted api_secret, dashboard link, and disconnect option.
5. Health detail view per site — memory, disk, plugin and theme updates, last error.

== Changelog ==

= 2.0.6 =
* Fix: "remove site" from the dashboard no longer triggers a fatal error on the site (and the WordPress recovery-mode emails that came with it); every remote command now fails gracefully instead of ever escalating to a PHP fatal.
* Fix: a re-delivered management command (network retry / re-dispatch) is no longer executed twice — plugin/theme install, update and delete are now idempotent per command id.
* New: render Hubbee content tokens without Elementor Pro, via the `[bz_text key="hero_title"]` shortcode and the "Hubbee Token" block.
* Privacy: visitor analytics is now strictly opt-in (off by default); the external-services disclosure now fully documents the site-management command surface and the on-request user list.
* Compatibility: tested up to WordPress 7.0. Full source published at https://github.com/TimurEfendi/hubbee-wordpress-plugin.

= 2.0.5 =
* Fix: the machine-to-machine API endpoint now falls back to the Hubbee API lane (api.hubbee.io) when no endpoint override is configured, so heartbeat, command polling and event delivery always reach a live endpoint.
* Refactor: removed an unused legacy connection-test code path.

= 2.0.4 =
* Fix: the Hubbee Agent settings page title was unreadable under an OS dark color scheme (light text on WordPress's light admin background). The admin UI now renders consistently in light mode, matching the WordPress backend.

= 2.0.2 =
* Add: `hubbee_heartbeat_enabled` and `hubbee_health_report_enabled` filter hooks (default true) so site owners can opt out of all telemetry without disconnecting from Hubbee Cloud.
* Add: site-reconcile RPC drops phantom components from WordPress when removed in the SaaS.
* Fix: element uninstall propagates fully to WordPress and cleans up the runtime chunk file.
* Fix: token bindings resolve correctly on render so bound values reach the frontend.
* Refactor: slug-specific code moved out of the plugin runtime into per-effect chunks for cleaner contract boundaries.
* Improve: integrity-checked SHA-256 chunk download with immediate manifest cache flush across the three asset endpoints.

= 2.0.1 =
* Fix: pre-launch security hardening — token hash, idempotent enrollment, CSP refinements, ops logging.
* Fix: enrollment now succeeds for `localhost`, supports snapshot wizard freshness gating.
* Fix: site-status consistency overhaul — connection_status as single source of truth.
* Add: shared `fetchWithRetry` and chunk-manifest loader infrastructure.

= 2.0.0 =
* Initial public release as "Hubbee".
* Token-based content management with HMAC-SHA256 push from SaaS.
* Elementor Dynamic Tags integration for token rendering.
* Asset library: backgrounds, elements, and text effects with on-demand chunk download.
* Health monitoring with three report levels (ping, snapshot, deep).
* Auto-upgrade migration from "Brandzilla" predecessor (preserves existing content).

== Upgrade Notice ==

= 2.0.6 =
Fixes a fatal error on site removal, makes remote commands idempotent, adds Elementor-Pro-free token rendering (shortcode + block), and makes visitor analytics opt-in. Recommended for all sites.

= 2.0.5 =
Recommended. Hardens machine-to-machine endpoint resolution so heartbeat, commands and events reach the Hubbee API lane even without an explicit endpoint override. No configuration change required.

= 2.0.4 =
Cosmetic fix for the settings page title visibility under dark color schemes. No functional changes.

= 2.0.2 =
Recommended for all users. Adds the `hubbee_heartbeat_enabled` and `hubbee_health_report_enabled` filter hooks for stricter privacy policies, plus several reliability fixes around element uninstall and token rendering. Default behaviour is unchanged — telemetry continues exactly as before unless you explicitly disable it via filter.
