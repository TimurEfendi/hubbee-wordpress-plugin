# WordPress.org submission assets

> Reviewer notes, the Plugin Check record and the pre-submission checklist live
> in `docs/wp-org-submission-notes.md` — this file only covers the image slots.

These files map to the **`/assets/` directory of the plugin's SVN repository**
(NOT `trunk/` — wp.org serves them from `/assets/`, they are never bundled in
the plugin ZIP).

| File | wp.org slot | Source | Renderer |
|---|---|---|---|
| `icon-256x256.png` | Plugin icon (retina) | `public/brand/logo-light.svg` | sharp |
| `icon-128x128.png` | Plugin icon | `public/brand/logo-light.svg` | sharp |
| `banner-1544x500.png` | Header banner (retina) | `public/brand/banner-wordpress-org.svg` | headless Chrome |
| `banner-772x250.png` | Header banner | downscaled from the 1544 render | sharp |

All four are generated **deterministically and for free** from the vector
brand kit in `public/brand/` — no external design tool, no AI image
generation. To regenerate after a brand change, re-run the export
(see `public/brand/README.md` → PNG export pipeline).

## Still pending — screenshots

`screenshot-1.png` … `screenshot-N.png` (referenced by the `== Screenshots ==`
section in `readme.txt`) require **live plugin UI** and cannot be rendered from
vectors. They must be captured from a WordPress admin with the Hubbee plugin
active (e.g. the connection screen, the pushed-content view, the health panel).
Capture them once a test WordPress admin is reachable, then drop them here.
