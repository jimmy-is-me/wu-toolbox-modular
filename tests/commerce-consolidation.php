<?php
/** CLI-only consolidation checks; no database, merchant API or invoice writes. */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');
define('WUTM_PATH', dirname(__DIR__) . '/');
// Model an already-loaded official integration; its unchanged source is checked below.
define('WOOECPAY_VERSION', 'fixture');
$enabled = ($argv[1] ?? 'enabled') !== 'disabled';
$options = [
    'wutm_module_ecpay_tools' => $enabled ? 1 : 0,
    'wutm_module_newebpay_tools' => 0,
    'wutm_module_ezpay_invoice' => 1, 'wutm_module_ecpay_invoice' => 1,
    'wumetax_module_ezpay_invoice' => 1, 'wumetax_module_ecpay_invoice' => 1,
    'wc_woomp_enabled_ezpay_invoice' => 'yes', 'wc_woomp_enabled_ecpay_invoice' => 'yes',
    'wc_woomp_ezpay_invoice_merchant_id' => 'retired-fixture',
    'wooecpay_enabled_invoice' => 'no', 'wooecpay_invoice_merchant_id' => 'official-fixture',
    'fixture_order_meta' => ['_ezpay_invoice_data'=>['InvoiceNumber'=>'OLD-TEST'], '_ecpay_invoice_data'=>['InvoiceNumber'=>'OLD-EC-TEST']],
];
$before = $options; $hooks = []; $menus = []; $allowed = true;
function get_option($key, $default=false) { return $GLOBALS['options'][$key] ?? $default; }
function add_action($name, $fn, $priority=10, ...$args) { $GLOBALS['hooks'][$name][$priority][] = $fn; }
function add_filter(...$args) { add_action(...$args); }
function add_submenu_page(...$args) { $GLOBALS['menus'][$args[4]] = $args; }
function current_user_can($cap) { return $GLOBALS['allowed']; }
function admin_url($path) { return '/wp-admin/' . $path; }
function esc_url($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
class WooCommerce {}
require WUTM_PATH . 'core/module-registry.php';
$modules = wutm_modules(); $groups = wutm_grouped_modules(); $names = array_keys($groups);
foreach (['ezpay-invoice','ecpay-invoice'] as $retired) {
    check(!isset($modules[$retired]), 'Retired card must not be registered');
    if($retired==='ecpay-invoice') check(!is_dir(WUTM_PATH . 'modules/' . $retired), 'Retired standalone ECPay runtime must not ship');
}
check(is_file(WUTM_PATH . 'modules/newebpay-tools/module.php'), 'Combined tool exists');
check(!isset($groups['電商支付工具']) && !isset($groups['電商電子發票']), 'Old categories must be absent');
check(array_keys($groups['電商第三方金物流']) === ['payuni-payment','linepay-payment','ecpay-tools','newebpay-tools'], 'Combined tool follows ECPay in unified category');
$index = array_search('電商第三方金物流', $names, true);
check($names[$index-1] === '電商工具' && $names[$index+1] === '電商優化工具', 'Category ordering retained');
foreach (json_decode(file_get_contents(__DIR__ . '/ecpay-tools-source-files.json'), true) as $file => $digest) {
    $source = file_get_contents(WUTM_PATH . 'modules/ecpay-tools/' . $file);
    if (basename($file) === 'LICENSE' || in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['php','js','css','md','txt','html','json','svg','xml','po','pot','lock'], true)) $source = preg_replace('/^\xEF\xBB\xBF/', '', str_replace("\r\n", "\n", $source));
    check(hash('sha256', $source) === $digest, 'Retained official integration changed: ' . $file);
}
require WUTM_PATH . 'core/module-loader.php';
foreach ($hooks['plugins_loaded'][20] ?? [] as $callback) $callback();
foreach ($hooks['admin_menu'][10] ?? [] as $callback) $callback();
check(isset($menus['wu-ecpay-tools']) === $enabled, 'Original ECPay entry follows original module switch');
check(!isset($menus['wu-ezpay-invoice']) && !isset($menus['wu-ecpay-invoice']), 'Stale enabled flags cannot revive retired menus');
foreach (array_keys($hooks) as $hook) check(!preg_match('/^wp_ajax_(gen_invoice|update_invoice|invalid_invoice)/', $hook), 'No retired invoice AJAX handlers');
if ($enabled) {
    check($menus['wu-ecpay-tools'][0] === 'wu-toolbox-modular' && $menus['wu-ecpay-tools'][3] === 'manage_woocommerce', 'Original menu parent and capability retained');
    ob_start(); $menus['wu-ecpay-tools'][5](); $html = ob_get_clean();
    check(strpos($html, 'wc-settings&amp;tab=wooecpay_setting&amp;section=ecpay_main') !== false, 'Original WooCommerce settings link retained');
    $allowed = false; ob_start(); $menus['wu-ecpay-tools'][5](); check(ob_get_clean() === '', 'Unauthorized user cannot render settings entry');
    $files = array_map(static function($file){return str_replace('\\','/',$file);}, get_included_files());
    check(in_array(str_replace('\\','/',WUTM_PATH . 'modules/ecpay-tools/module.php'), $files, true), 'ECPay early bootstrap retained');
}
check($options === $before, 'Never delete or convert merchant/retired options, service switches or order metadata');
echo 'Commerce consolidation passed: ' . ($enabled ? 'enabled' : 'disabled') . "; retained source, categories, stale flags, menu/capability and data preservation.\n";
