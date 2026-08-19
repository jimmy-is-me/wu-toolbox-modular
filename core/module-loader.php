<?php
defined('ABSPATH') || exit;

add_action('plugins_loaded', function (): void {
    foreach (wutm_modules() as $key => $module) {
        if (!wutm_is_enabled($key)) continue;
        if (($module['requires'] ?? '') === 'woocommerce' && !class_exists('WooCommerce')) continue;
        $file = WUTM_PATH . 'modules/' . $key . '/module.php';
        if (is_readable($file)) require_once $file;
    }
}, 20);
