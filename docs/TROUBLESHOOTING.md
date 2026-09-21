# Hubbee Troubleshooting Guide

This guide helps diagnose and resolve common issues with Hubbee component rendering.

## Quick Diagnostics

### Enable Debug Mode

**Option 1: wp-config.php (recommended)**
```php
define( 'HUBBEE_DEBUG', true );
```

**Option 2: Browser Console**
```javascript
window.HUBBEE_DEBUG = true;
HubbeeLive.hydrate();
```

### Check Manifest Status
```javascript
// View current manifest
console.log(window.__BZ_MANIFEST__);

// Check specific component
document.querySelectorAll('[data-bz-component]').forEach(el => {
  console.log(el.dataset.bzComponent, el.dataset.bzHydrated);
});
```

### Check WordPress Debug Log
```bash
tail -f wp-content/debug.log | grep Hubbee
```

---

## Common Issues

### 1. Ghost Rendering (Deleted component still visible)

**Symptoms:**
- Component appears on frontend despite being deleted in SaaS
- Old version of component shows instead of updated version

**Diagnostic Steps:**
1. Open Browser Console
2. Enable debug mode: `window.HUBBEE_DEBUG = true`
3. Reload page
4. Check if component is in manifest

**Possible Causes & Solutions:**

| Symptom | Cause | Solution |
|---------|-------|----------|
| Component in manifest but deleted in SaaS | Manifest cache stale | Push again from Hubbee, or fire `hubbee_cache_purge_needed` |
| Not in manifest but visible | Page cache | Purge WP Rocket/LiteSpeed cache |
| SSR fallback visible, no React | JavaScript error | Check browser console for errors |
| Elementor shows old data | Widget cache | Elementor → Regenerate CSS & Data |
| Cloudflare cached version | CDN cache | Purge Cloudflare cache |

**Nuclear Option (clear all caches):**

Every push purges the plugin's own caches automatically. To force it outside a
push, fire the action the AgentManager listens on:
```php
do_action('hubbee_cache_purge_needed');
```

### 2. Component Not Rendering

**Symptoms:**
- Empty space where component should be
- "Komponente nicht verfügbar" message (editors only)
- SSR fallback shows but React doesn't hydrate

**Diagnostic Steps:**
```javascript
// Check if mountpoint exists
const el = document.querySelector('[data-bz-component="your-slug"]');
console.log('Mountpoint:', el);
console.log('Config:', el?.dataset.bzConfig);
console.log('Hydrated:', el?.dataset.bzHydrated);
```

**Possible Causes & Solutions:**

| Symptom | Cause | Solution |
|---------|-------|----------|
| No mountpoint in DOM | Shortcode/widget not added | Add component via Elementor or shortcode |
| Mountpoint exists, no config | Component not in local DB | Re-push component from SaaS |
| Config present, not hydrated | JS bundle not loaded | Check if hubbee-live.min.js loaded |
| JS error in console | Code issue | Check error, may need rebuild |
| "not in manifest" in console | Component inactive/deleted | Verify component status in SaaS |

### 3. Contact Form Not Submitting

**Symptoms:**
- Form submits but nothing happens
- Error message appears
- Honeypot false positive

**Diagnostic Steps:**
```javascript
// Check network tab for submission request
// Look for POST to /functions/v1/contact-submit

// Check console for errors
window.HUBBEE_DEBUG = true;
```

**Possible Causes & Solutions:**

| Symptom | Cause | Solution |
|---------|-------|----------|
| 401 Unauthorized | HMAC signature mismatch | Check API secret in WP settings |
| 429 Too Many Requests | Rate limit hit | Wait 1 minute (IP) or 1 hour (form) |
| 400 Validation Error | Field validation failed | Check form field configuration |
| Silent fail (200 OK but no email) | Honeypot triggered | User filled hidden field (likely bot) |
| Form not found (404) | Form ID mismatch | Verify form ID matches SaaS |

### 4. Styling Issues

**Symptoms:**
- Components render but look broken
- CSS not applied
- Background layers not visible

**Diagnostic Steps:**
```javascript
// Check if CSS loaded
document.querySelectorAll('link[href*="hubbee"]').forEach(l => console.log(l.href));

// Check CSS custom properties
getComputedStyle(document.querySelector('.bz-cf-wrapper'))
  .getPropertyValue('--bz-primary');
```

**Possible Causes & Solutions:**

| Symptom | Cause | Solution |
|---------|-------|----------|
| No styles at all | CSS not enqueued | Check if component CSS loaded |
| Theme overriding styles | Specificity conflict | Use more specific selectors |
| Background layers invisible | Z-index issue | Check parent container overflow |
| Motion not working | Reduced motion preference | Check `prefers-reduced-motion` |

---

## Debug Commands

### WordPress Debug Log Patterns

```bash
# All Hubbee logs
grep "Hubbee" wp-content/debug.log

# Manifest operations
grep "Hubbee:Manifest" wp-content/debug.log

# Render operations
grep "Hubbee:Render" wp-content/debug.log

# Cache operations
grep "Hubbee:Cache" wp-content/debug.log

# Errors only
grep "Hubbee.*ERROR" wp-content/debug.log
```

### Browser Console Commands

```javascript
// Show all Hubbee mountpoints
document.querySelectorAll('[data-bz-component]')

// Show hydration status
Array.from(document.querySelectorAll('[data-bz-component]')).map(el => ({
  slug: el.dataset.bzComponent,
  hydrated: el.dataset.bzHydrated,
  version: el.dataset.bzVersion
}))

// Force re-hydration
HubbeeLive.destroyAll();
HubbeeLive.hydrate();

// Check manifest content
JSON.parse(document.getElementById('bz-manifest-data')?.textContent || '{}')
```

### Admin actions

The plugin ships no WP-CLI commands. WP Admin → Hubbee → Settings offers
exactly three actions:

- **Connect** — exchange a connection code for the per-site secret
- **Test connection** — round-trip check against the Hubbee API
- **Disconnect** — drop the secret and halt all outbound calls

Everything else is driven from the Hubbee dashboard: component and token state
come down with each push, and the remote debug-log viewer reads `debug.log`
without shell access.

---

## Reporting Issues

When reporting issues, include:

1. **Environment:**
   - WordPress version
   - PHP version
   - Active caching plugins
   - CDN in use (Cloudflare, etc.)

2. **Debug Output:**
   - Relevant debug.log entries
   - Browser console errors
   - Network tab screenshots

3. **Reproduction Steps:**
   - What you did
   - What you expected
   - What actually happened

4. **Component Info:**
   - Component slug
   - Component type (contact-form, hero, etc.)
   - When issue started

---

## Architecture Reference

```
┌─────────────────────────────────────────────────────────────────┐
│                        Request Flow                              │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  1. Page Load                                                    │
│     └→ WordPress renders shortcode/widget                        │
│        └→ MountpointRenderer checks ManifestService              │
│           └→ Is component active? (manifest gate)                │
│              ├→ YES: Render mountpoint + SSR fallback            │
│              └→ NO: Render placeholder (editors) / nothing       │
│                                                                  │
│  2. Client Hydration                                             │
│     └→ hubbee-live.min.js loads                                  │
│        └→ ComponentHydrator.hydrateAll()                         │
│           └→ Read manifest from <script id="bz-manifest-data">   │
│              └→ For each [data-bz-component]:                    │
│                 └→ Is slug in manifest AND active?               │
│                    ├→ YES: Load React component, hydrate         │
│                    └→ NO: Hide element / show debug message      │
│                                                                  │
│  3. Cache Layers                                                 │
│     ├→ Memory cache (per-request)                                │
│     ├→ Transient cache (5 minutes)                               │
│     ├→ Stale cache (wp_options, for network failures)            │
│     ├→ Page cache (WP Rocket, LiteSpeed, etc.)                   │
│     └→ CDN cache (Cloudflare, etc.)                              │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## Contact

For unresolved issues, contact support with the debug information above.
