<?php
/**
 * WU Toolbox Modular：美安（SHOP.COM）串接。
 *
 * 模組化整合 RID／Click_ID 追蹤、訂單成立與取消 API、商品 XML Feed、
 * HPOS 訂單資訊與錯誤通知。功能來源依使用者指定的專案重構版整合。
 */

defined('ABSPATH') || exit;

// 若網站已另外啟用原始美安串接外掛，沿用其實例，避免重複註冊 Hook 與類別。
if (class_exists('ShopCom_Plugin') || class_exists('ShopCom_Settings')) return;

defined('SHOPCOM_VERSION') || define('SHOPCOM_VERSION', '2.0.0-wutm');
defined('SHOPCOM_PLUGIN_FILE') || define('SHOPCOM_PLUGIN_FILE', WUTM_FILE);
defined('SHOPCOM_PLUGIN_DIR') || define('SHOPCOM_PLUGIN_DIR', __DIR__ . '/');
defined('SHOPCOM_PLUGIN_URL') || define('SHOPCOM_PLUGIN_URL', WUTM_URL . 'modules/shopcom-integration/');
defined('SHOPCOM_PLUGIN_BASENAME') || define('SHOPCOM_PLUGIN_BASENAME', plugin_basename(WUTM_FILE));

add_action('before_woocommerce_init', function (): void {
    if (!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) return;
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WUTM_FILE, true);
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', WUTM_FILE, true);
});

require_once __DIR__ . '/includes/class-shopcom-settings.php';
require_once __DIR__ . '/includes/class-shopcom-tracker.php';
require_once __DIR__ . '/includes/class-shopcom-order-api.php';
require_once __DIR__ . '/includes/class-shopcom-xml-feed.php';
require_once __DIR__ . '/includes/class-shopcom-admin-order-column.php';
require_once __DIR__ . '/includes/class-shopcom-notifier.php';

add_action('plugins_loaded', function (): void {
    if (!class_exists('WooCommerce') || isset($GLOBALS['wutm_shopcom_integration'])) return;

    $settings = new ShopCom_Settings();
    $GLOBALS['wutm_shopcom_integration'] = [
        'settings' => $settings,
        'tracker' => new ShopCom_Tracker($settings),
        'order_api' => new ShopCom_Order_API($settings),
        'xml_feed' => new ShopCom_XML_Feed($settings),
        'admin_order_column' => new ShopCom_Admin_Order_Column($settings),
        'notifier' => new ShopCom_Notifier($settings),
    ];

    if (get_option('wutm_shopcom_version', '') !== SHOPCOM_VERSION) {
        if (false === get_option(ShopCom_Settings::OPTION_KEY, false)) {
            add_option(ShopCom_Settings::OPTION_KEY, [], '', false);
        }
        update_option('wutm_shopcom_version', SHOPCOM_VERSION, false);
        add_action('init', 'flush_rewrite_rules', 99);
    }
}, 20);
