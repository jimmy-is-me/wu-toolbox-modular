<?php
defined('ABSPATH') || exit;
add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce') || defined('WPBR_ESUN_PAYMENT_VERSION')) return;
    $base = __DIR__ . '/vendor/wpbr-esun-payment/';
    define('ESUN_PLUGIN_DIR', $base);
    define('ESUN_PLUGIN_URL', plugin_dir_url(__FILE__) . 'vendor/wpbr-esun-payment/');
    define('ESUN_BASENAME', plugin_basename($base . 'wpbr-esun-payment.php'));
    define('ESUN_INCLUDES_DIR', $base . 'includes/');
    define('WPBR_ESUN_PAYMENT_VERSION', '2.0.0');
    require_once $base . 'vendor/autoload.php';
    \WPBrewer\ESun\Payment\ESunPayment::init();
}, 25);
add_action('admin_menu', function () {
    add_submenu_page('wu-toolbox-modular', '玉山銀行金流工具', '玉山銀行金流工具', 'manage_woocommerce', 'wu-esun-payment', function () {
        echo '<div class="wrap"><h1>玉山銀行金流工具</h1><div class="card"><p>整合玉山信用卡一次付清與分期付款。請先完成商店申請，填入 MID、MAC 並以測試環境驗證付款及回傳結果。</p><p><strong>注意：</strong>此外掛僅提供程式串接整合服務，並非金流服務，商家需自行與玉山銀行簽約才可以使用。</p><p>若已啟用原版玉山外掛，本模組不會重複載入。</p><p><a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=esun')) . '">玉山金流設定</a></p><p><a href="' . esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')) . '">啟用付款方式</a></p></div></div>';
    });
});
