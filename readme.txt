=== Hubbee ===
Contributors: 2brandsmedia
Tags: site management, content sync, multi-site, agency, remote management
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 2.2.0
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

**Dynamic visual-effect chunks**: when you assign a background, element, or text-effect to a site in your Hubbee dashboard, the plugin downloads the corresponding compiled JavaScript chunk from your Hubbee Cloud account and stores it locally in `wp-content/uploads/hubbee/chunks/`. Each chunk is integrity-checked via SHA-256 hash before being written. The plugin ships with a fixed runtime plus the open-source vendor libraries the WebGL/Canvas effects rely on — React, Three.js and React-Three-Fiber, about 1 MB in total (all minified but readable, not obfuscated) — which load only the locally-stored chunks via `wp_enqueue_script`. No third-party CDN at runtime, no per-page-view external requests.

**What is sent to Hubbee Cloud automatically (when connected):**

* Heartbeat pings (no body, JWT-authenticated, default every 5 minutes)
* Optional health snapshots (WordPress version, PHP version, plugin version, token count)
* Optional deep health reports (memory and disk usage percentages, available plugin and theme update list, last error message)
* Token push receipts (per content update — confirms which tokens were applied)
* Visitor analytics — anonymous and cookieless (page path, a random per-session hash, referrer domain, user agent), aggregated per day. **You choose this when you connect the site**: the connect screen shows a clearly labelled checkbox, and your answer is stored on your own site. Uncheck it and no tracking script is ever loaded. You can change your mind at any time from your Hubbee dashboard (workspace settings) or in code via the `hubbee_analytics_enabled` filter. Nothing is collected while the site is not connected, and no cookies are set at any point.

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

1. Install the plugin and activate it. You can also download `hubbee.zip` from your Hubbee dashboard (or from https://hubbee.io/en/download) and upload it via **Plugins → Add New → Upload Plugin** — the dashboard always serves the build that matches your account.
2. In the Hubbee dashboard at https://app.hubbee.io, click **Add Site**, generate a one-time connection code, copy it.
3. Back in WordPress Admin, navigate to **Hubbee → Settings**, paste the connection code, click **Connect**.
4. Within seconds, the site appears in your Hubbee dashboard with online status and basic health information. Token-push, asset-assign, and health-monitoring are now active.

== Frequently Asked Questions ==

= Do I need a Hubbee account? =

Yes. Hubbee is a service-plugin — the WordPress side is the agent, the SaaS at https://hubbee.io is the dashboard. A permanent free plan is available; no credit card required.

= Why does the plugin use the `bz/v1` REST namespace and `BZ_*` PHP constants? =

Hubbee evolved from a previous product called "Brandzilla". The internal `bz_*` prefixes (database tables, options, action hooks, REST namespace, JavaScript hydration attributes) are kept for backwards-compatibility with existing installs — renaming them would force a destructive migration on every customer site. The plugin name and all user-facing surface are now fully "Hubbee".

= How do I turn visitor analytics off? =

Three ways, all equivalent — the plugin stores one option (`bz_analytics_enabled`) and every path writes it:

1. **When connecting**: uncheck "Send visitor analytics to Hubbee" on the connect screen. Nothing is ever loaded.
2. **Later, from the dashboard**: workspace settings in your Hubbee account. The change reaches every connected site.
3. **In code**: `add_filter( 'hubbee_analytics_enabled', '__return_false' );` in a mu-plugin. This wins over both of the above.

**Hubbee → Settings** always shows the state that is actually in effect for the site. When analytics is off, the tracking script is not enqueued at all — there is no beacon, no request, and no cookie.

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

Purge any page-level / CDN cache. The plugin's manifest tells it which components are still active; orphaned components are removed on the next manifest refresh. Full troubleshooting flow at https://hubbee.io/help/troubleshooting-plugin.

= Is Elementor required? =

For content tokens, no. They render on any WordPress site via the shortcode `[bz_text key="hero_title"]` and the "Hubbee Token" block; site health, update inventory and monitoring work without Elementor as well. Elementor only adds Dynamic-Tag convenience for token rendering inside Elementor templates.

For the asset library (backgrounds, animated elements, text effects), yes — embedding those assets currently requires Elementor, where they appear as container background options, widgets and heading styles. WordPress-native embedding without Elementor is in development and not shipped yet.

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

= 2.2.0 =
* New: the connect screen now asks explicitly whether this site should send visitor analytics. Your answer is stored on your own site when the connection is established, and the Hubbee Agent settings page shows the current state in plain words. Unchecking the box means no tracking script is ever enqueued.
* Fix: the plugin reported "analytics off" to the dashboard for every site that had never received an explicit toggle command, while the tracking script was in fact running (a mismatch between the tracker's default and the default used when reporting the state). Both now agree, so the dashboard shows the truth.
* Docs: the readme describes the analytics behaviour as it actually ships, and the changelog documents the 2.0.9 default change that was previously missing.

= 2.1.0 =
* New: monitoring cadence now follows your Hubbee plan. The SaaS sends the heartbeat and health-check intervals with every heartbeat response (and command poll), and the plugin applies them dynamically — an explicit 0 cleanly unschedules the heartbeat and the scheduled health snapshots (Free plan). The daily deep check stays active on every plan so the update inventory keeps refreshing.
* New: `hubbee_health_reports_enabled` filter — site owners can disable scheduled health snapshot reports in code, matching the existing `hubbee_heartbeat_enabled` opt-out.
* Dev: interval sync consolidated into a single `IntervalSync` class shared by the heartbeat response and the command poller.

= 2.0.9 =
* Changed: visitor analytics became **on by default** for sites connected to a Hubbee workspace, controlled by a single workspace-wide switch in the dashboard instead of a per-site opt-in. This entry was missing from earlier releases of this readme — it is documented here for the record, and 2.2.0 replaces the silent default with an explicit choice on the connect screen.

= 2.0.8 =
* New: visitor analytics can now be switched on from the Hubbee dashboard (Site → Settings → General). The dashboard sends an explicit opt-in command; the plugin reports the current state back with the settings snapshot. Analytics remains strictly opt-in and off by default.
* Fix: the tracking beacon sends each page view exactly once — previously every tab switch could re-send the same page view and inflate the numbers. The beacon now uses `pagehide` instead of `beforeunload`, and unique-visitor/bounce calculation moved server-side for accurate statistics.
* Fix: smoother text-effect rendering (whitespace normalisation) and live ball-pit configurator parameters, delivered via the dynamic effect chunks.

= 2.0.7 =
* i18n: plugin source strings are now English, with a full German (de_DE) translation and an accurate POT — sites in either language are fully localised.
* Compatibility: resolves all official WordPress.org Plugin Check errors (58 → 0), for a clean directory submission.
* Security: unslash and sanitize every `$_SERVER`/`$_POST` read, escape the cron URL, and gate debug logging behind `WP_DEBUG`.
* Assets: icon and banner rendered from the vector brand kit. No functional changes to the site-management surface since 2.0.6.

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

= 2.2.0 =
The connect screen now asks explicitly whether the site should send visitor analytics, and the settings page states the current answer. Also fixes a reporting mismatch that could make the dashboard show "analytics off" for a site that was in fact tracking. Recommended for all sites.

= 2.0.7 =
English source strings + full German translation, WordPress.org Plugin Check compliance, and security hardening ($_SERVER/$_POST sanitisation, cron URL escaping, WP_DEBUG-gated logging). No change to the site-management behaviour. Recommended for all sites.

= 2.0.6 =
Fixes a fatal error on site removal, makes remote commands idempotent, adds Elementor-Pro-free token rendering (shortcode + block), and makes visitor analytics opt-in. Recommended for all sites.

= 2.0.5 =
Recommended. Hardens machine-to-machine endpoint resolution so heartbeat, commands and events reach the Hubbee API lane even without an explicit endpoint override. No configuration change required.

= 2.0.4 =
Cosmetic fix for the settings page title visibility under dark color schemes. No functional changes.

= 2.0.2 =
Recommended for all users. Adds the `hubbee_heartbeat_enabled` and `hubbee_health_report_enabled` filter hooks for stricter privacy policies, plus several reliability fixes around element uninstall and token rendering. Default behaviour is unchanged — telemetry continues exactly as before unless you explicitly disable it via filter.
