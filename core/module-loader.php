<?php
defined('ABSPATH') || exit;

// Payment gateways must register compatibility and callback hooks before
// WooCommerce finishes its own plugins_loaded bootstrap.
$wutm_early_module_keys = [
    'payuni-payment',
    'linepay-payment',
    'discord-notifications',
    'member-loyalty',
    'ecpay-tools',
    'shopcom-integration',
];
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
        $requires_module = (string) ($module['requires_module'] ?? '');
        if ($requires_module !== '' && !wutm_is_enabled($requires_module)) continue;
        $file = WUTM_PATH . 'modules/' . $key . '/module.php';
        if (is_readable($file)) require_once $file;
    }
}, 20);

unset($wutm_early_module_key, $wutm_early_module_file);

/** Keep the Transients cleanup event aligned with both Toolbox and module settings. */
function wutm_sync_transients_cleanup_schedule(bool $module_enabled, ?array $settings = null): void {
    $defaults = [
        'enabled' => false,
        'auto_cleanup' => true,
        'cleanup_frequency' => 'daily',
    ];
    $settings = wp_parse_args($settings ?? get_option('wu_transients_manager_settings', []), $defaults);
    $hook = 'wu_transients_auto_cleanup';

    if (!$module_enabled || empty($settings['enabled']) || empty($settings['auto_cleanup'])) {
        wp_clear_scheduled_hook($hook);
        return;
    }

    $frequency = sanitize_key((string) $settings['cleanup_frequency']);
    if (!in_array($frequency, ['hourly', 'twicedaily', 'daily', 'weekly'], true)) {
        $frequency = 'daily';
    }

    if (wp_next_scheduled($hook) && wp_get_schedule($hook) !== $frequency) {
        wp_clear_scheduled_hook($hook);
    }
    if (!wp_next_scheduled($hook)) {
        wp_schedule_event(time(), $frequency, $hook);
    }
}

add_action('wutm_module_toggled', static function (string $key, bool $enabled): void {
    if ($key === 'transients-manager') {
        wutm_sync_transients_cleanup_schedule($enabled);
    }
}, 10, 2);
