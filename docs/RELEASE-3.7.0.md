# v3.7.0 — Shared settings-page components

Common light settings headers, primary/secondary/destructive controls, tabs, text fields and panel surfaces now share a scoped component layer. The dashboard retains its branding. Data/graph geometry, preview output, editor and dialog internals remain module-owned; adaptation adds classes without moving or replacing form nodes.

The PHP page helper and explicit JavaScript adaptation API provide a reusable entry point for future modules. A baseline-aware contract check inventories 86 existing modules registering admin pages and requires new settings modules to use the common root/helper. It is a structural guard, not a replacement for visual and functional review.

Ownership detection still uses registered callback source files, excluding native and foreign admin screens. No frontend assets, polling, new business queries or global MutationObserver were added. Asset timestamps invalidate presentation caches. This release does not add client-access switches, move client menus or broaden permissions.

Validation includes 12 cross-template responsive cases at 320/390/768/1440 widths with equal computed header styles, original form fields/states/owners/listeners, preview isolation and explicit dynamic adaptation. Existing admin browser cases, live media conversion controls, appearance/player checks, email, SEO, content, SQL fixtures and CI remain in place. Production-site plugin combinations still require installation acceptance testing; no production settings were changed during development.
