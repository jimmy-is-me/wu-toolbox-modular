# Modules

Every module is self-contained at `modules/<slug>/module.php`. The bootstrap only loads files whose switch is enabled.

| Group | Module | Dependency |
| --- | --- | --- |
| Security | hide-login-page, login-limiter, captcha, audit-logger | — |
| Content | content-duplicator, post-optimization, revision-manager, 404-redirector | — |
| Admin | admin-bar-cleaner, dashboard-status, enhanced-user-list, user-switcher | — |
| Performance | no-cache-pages, transients-manager, moving-mode | — |
| Performance | woocommerce-optimizer | WooCommerce |
| Media | media-encoder, enhanced-downloader, plugin-downloader | — |
| Code | head-footer-code | — |
| Monitoring | email-tracking, system-monitor | — |

## Loading guarantees

1. WordPress loads only the tiny bootstrap and `core/` files.
2. At `plugins_loaded`, the loader checks the module switch before it reads the module file.
3. A disabled module registers no WordPress hooks and has no frontend runtime cost.
4. WooCommerce-only code is skipped completely when WooCommerce is unavailable.

## Creating a module

Add an entry to `core/module-registry.php`, then add `modules/<slug>/module.php`. Keep installation/cleanup hooks within the module and guard all direct requests with WordPress capability and nonce checks.


## Admin routes

When a module is enabled, it appears under the **WU Toolbox** left menu. Every module uses the stable route `admin.php?page=wu-<module>`; for example `wu-404-redirector`, `wu-system-monitor`, `wu-post-optimization`, `wu-moving-mode`, and `wu-no-cache-pages`. The core bridges older internal pages to these routes so existing module settings remain available.
