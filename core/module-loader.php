<?php
defined('ABSPATH') || exit;

// Payment gateways must register compatibility and callback hooks before
// WooCommerce finishes its own plugins_loaded bootstrap.
$wutm_early_module_keys = ['payuni-payment', 'linepay-payment', 'discord-notifications'];
foreach ($wutm_early_module_keys as $wutm_early_module_key) {
    if (!wutm_is_enabled($wutm_early_module_key)) continue;

    $wutm_early_module_file = WUTM_PATH . 'modules/' . $wutm_early_module_key . '/module.php';
    if (is_readable($wutm_early_module_file)) require_once $wutm_early_module_file;
}

add_action('plugins_loaded', function () use ($wutm_early_module_keys): void {
    foreach (wutm_modules() as $key => $module) {
        if (in_array($key, $wutm_early_module_keys, true)) continue;
        if (!wutm_is_enabled($key)) continue;
        $requires = $module['requires'] ?? '';
        if ($requires === 'woocommerce' && !class_exists('WooCommerce')) continue;
        if ($requires === 'translatepress' && !class_exists('TRP_Translate_Press')) continue;
        $file = WUTM_PATH . 'modules/' . $key . '/module.php';
        if (is_readable($file)) require_once $file;
    }
}, 20);

unset($wutm_early_module_key, $wutm_early_module_file);
