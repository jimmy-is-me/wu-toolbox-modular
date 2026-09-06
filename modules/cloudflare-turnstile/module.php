<?php
/**
 * Cloudflare Turnstile integration bundled as an on-demand WU module.
 */
defined('ABSPATH') || exit;

if (!function_exists('cfturnstile_settings_page')) {
    $wutm_turnstile_file = __DIR__ . '/vendor/simple-cloudflare-turnstile/simple-cloudflare-turnstile.php';
    if (is_readable($wutm_turnstile_file)) {
        require_once $wutm_turnstile_file;
    }
    unset($wutm_turnstile_file);
}
