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
add_action('before_woocommerce_init',static function() {
    if(class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        // Source invoice/checkout UI is classic; do not falsely advertise Blocks/HPOS.
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks',WUTM_FILE,false);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables',WUTM_FILE,false);
    }
});
