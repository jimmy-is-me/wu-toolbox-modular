<?php
/**
 * Cloudflare Turnstile integration bundled as an on-demand WU module.
 */
defined('ABSPATH') || exit;

if (!function_exists('cfturnstile_settings_page')) {
    $wutm_turnstile_file = __DIR__ . '/vendor/simple-cloudflare-turnstile/simple-cloudflare-turnstile.php';
    if (is_readable($wutm_turnstile_file)) {
        require_once $wutm_turnstile_file;
        // The vendor plugin normally places this below Settings. Register it
        // below WU Toolbox so this on-demand module always has a valid page.
        remove_action('admin_menu', 'cfturnstile_create_menu');
        add_action('admin_menu', static function (): void {
            add_submenu_page(
                'wu-toolbox-modular',
                'Cloudflare Turnstile',
                'Cloudflare Turnstile',
                'manage_options',
                'cfturnstile',
                'cfturnstile_settings_page'
            );
        }, 999);
    }
    unset($wutm_turnstile_file);
}
