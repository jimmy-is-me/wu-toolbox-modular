<?php
/** Verify the former shipping card remains active inside WC optimization. */
define('ABSPATH', __DIR__ . '/');
class WooCommerce {}
class WC_Shipping_Method {}
$options = [
    'wutm_module_shipping_optimization_tools' => 1,
    'wutm_module_wc_optimization_tools' => 0,
    'wu_woo_enable_island_shipping' => 1,
    'wu_woo_enable_711_shipping' => 1,
];
$hooks = [];
function add_action($hook, $callback, $priority = 10, $accepted_args = 1): void {
    global $hooks;
    $hooks[$hook][$priority][] = $callback;
}
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1): void {
    add_action($hook, $callback, $priority, $accepted_args);
}
function get_option($key, $default = false) {
    global $options;
    return array_key_exists($key, $options) ? $options[$key] : $default;
}
function update_option($key, $value, $autoload = null): void {
    global $options;
    $options[$key] = $value;
}
function delete_option($key): void {
    global $options;
    unset($options[$key]);
}

require dirname(__DIR__) . '/core/module-registry.php';
if (isset(wutm_modules()['shipping-optimization-tools'])) throw new RuntimeException('Retired shipping card is still shown');
foreach ($hooks['plugins_loaded'][19] ?? [] as $callback) $callback();
if (!wutm_is_enabled('wc-optimization-tools')) throw new RuntimeException('Old shipping activation was not migrated');

require dirname(__DIR__) . '/core/woocommerce-tools.php';
new WU_WooCommerce_Optimizer('commerce');
$init = $hooks['init'][10] ?? [];
$methods = array_map(static fn($callback) => is_array($callback) ? $callback[1] : '', $init);
if (!in_array('register_taiwan_address', $methods, true)) throw new RuntimeException('Island shipping address hooks were not retained');
if (!in_array('register_711_shipping', $methods, true)) throw new RuntimeException('7-11 shipping hooks were not retained');

echo "WC shipping merge checks passed.\n";
