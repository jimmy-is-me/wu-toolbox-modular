<?php
/**
 * Cloudflare Turnstile integration bundled as an on-demand WU module.
 */
defined('ABSPATH') || exit;

if (!function_exists('cfturnstile_settings_page')) {
    $wutm_turnstile_file = __DIR__ . '/vendor/simple-cloudflare-turnstile/simple-cloudflare-turnstile.php';
    if (is_readable($wutm_turnstile_file)) {
        require_once $wutm_turnstile_file;
        // The WU core owns the settings page registration. Remove the vendor
        // menu hook so it cannot register a duplicate page under Settings.
        remove_action('admin_menu', 'cfturnstile_create_menu');
    }
    unset($wutm_turnstile_file);
}
