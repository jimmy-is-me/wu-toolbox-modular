<?php
defined('ABSPATH') || exit;
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) return;
    // Never load alongside an existing Woomp/RY integration.
    if (defined('RY_WT_VERSION') || defined('WOOMP_VERSION') || defined('ECPAYINVOICE_PLUGIN_DIR')) return;
    $base = __DIR__ . '/vendor/';
    define('RY_WT_VERSION', '1.0.0');
    define('RY_WT_PLUGIN_DIR', $base . 'ry-woocommerce-tools/');
    define('RY_WT_PLUGIN_URL', plugin_dir_url(__FILE__) . 'vendor/ry-woocommerce-tools/');
    define('RY_WT_PLUGIN_BASENAME', plugin_basename(__FILE__));
    require_once RY_WT_PLUGIN_DIR . 'class.ry-wt.main.php';
    // Load only ECPay: do not run the upstream all-provider bootstrap.
    if (is_admin()) require_once RY_WT_PLUGIN_DIR . 'class.ry-wt.admin.php';
    require_once RY_WT_PLUGIN_DIR . 'woocommerce/gateways/ecpay/ecpay-gateway.php';
    require_once RY_WT_PLUGIN_DIR . 'woocommerce/shipping/ecpay/ecpay-shipping.php';
    add_filter('woocommerce_get_settings_rytools', function ($settings, $section) {
        return $section === '' ? array_slice($settings, 0, 4) : $settings;
    }, 99, 2);
    add_filter('woocommerce_localisation_address_formats', array('RY_WT', 'add_address_format'));
    foreach (array('hidden','hidden_empty','hiddentext') as $field) add_filter('woocommerce_form_field_' . $field, array('RY_WT', 'form_field_' . $field), 20, 4);
    add_action('init', function () {
        load_plugin_textdomain('ry-woocommerce-tools', false, dirname(plugin_basename(__FILE__)) . '/vendor/ry-woocommerce-tools/languages');
    });
    add_filter('woocommerce_get_settings_pages', function ($pages) {
        require_once __DIR__ . '/invoice-settings.php';
        $pages[] = new WUTM_ECPay_Invoice_Settings();
        return $pages;
    });
    if (get_option('wc_woomp_enabled_ecpay_invoice') === 'yes') {
        foreach (array('gen_invoice','invalid_invoice','update_invoice') as $action) {
            add_action('wp_ajax_' . $action, function () {
                check_ajax_referer('invoice_handler', 'nonce');
                $id = isset($_POST['orderId']) && is_scalar($_POST['orderId']) ? absint($_POST['orderId']) : 0;
                if (!$id || !current_user_can('manage_woocommerce') || !current_user_can('edit_shop_order', $id)) wp_send_json_error('沒有操作此訂單發票的權限。', 403);
            }, 0);
        }
        define('ECPAYINVOICE_PLUGIN_DIR', $base . 'woomp-ecpay-invoice/');
        define('ECPAYINVOICE_PLUGIN_URL', plugin_dir_url(__FILE__) . 'vendor/woomp-ecpay-invoice/');
        spl_autoload_register(function ($class) use ($base) {
            if (strpos($class, 'ODS\\') === 0) {
                $file = $base . 'wp-metabox/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
                if (is_file($file)) require_once $file;
            }
        });
        foreach (array('apis','posts/ShopOrder','templates') as $folder) {
            foreach (glob(ECPAYINVOICE_PLUGIN_DIR . 'src/' . $folder . '/*.php') as $file) require_once $file;
        }
    }
}, 25);
add_action('admin_menu', function () {
    add_submenu_page('wu-toolbox-modular', '綠界金流/物流/電子發票工具', '綠界金流/物流/電子發票工具', 'manage_woocommerce', 'wu-ecpay-tools', function () {
        echo '<div class="wrap"><h1>綠界金流/物流/電子發票工具</h1><div class="card"><p>僅整合綠界功能，不載入 Woomp 的其他金流或結帳改版。若有原版 Woomp/RY 外掛，請先停用再使用本模組。</p><p>請先填寫各服務商店資料並完成測試交易、物流回傳與發票測試；金流、物流、電子發票的密鑰不可混用。使用傳統 WooCommerce 結帳，尚未驗證區塊結帳。</p><p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=rytools')) . '">金流與物流設定</a></p><p><a class="button" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=wutm_ecpay_invoice')) . '">電子發票設定</a></p><p>物流需另至 WooCommerce 運送區域新增綠界運送方式；電子發票預設不啟用。</p></div></div>';
    });
});
