<?php
/** WU 藍新金流／物流／EZPAY 電子發票工具。 */
defined('ABSPATH') || exit;
if(PHP_VERSION_ID<80000) { add_action('admin_notices',static function(){echo '<div class="notice notice-warning"><p>WU 藍新／EZPAY 工具需要 PHP 8.0 以上。</p></div>';}); return; }
define('WUTM_NEWEBPAY_TOOLS',true);
require_once __DIR__.'/runtime.php';
require_once WUTM_PATH.'core/invoice-modules.php';
require_once __DIR__.'/settings.php';
add_action('plugins_loaded','wutm_newebpay_boot',25);
wutm_invoice_register('ezpay');
add_action('init',static function() { load_plugin_textdomain('wu-newebpay-tools',false,dirname(plugin_basename(__FILE__)).'/vendor/languages'); });
// A component must not contradict other components' declarations for WUTM_FILE.
// Do not declare true to hide limitations either: keep this component's classic
// checkout/order-storage limits explicit in its settings and documentation.
