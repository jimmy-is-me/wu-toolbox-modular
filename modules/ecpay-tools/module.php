<?php
/**
 * WU 綠界金流、物流與電子發票工具。
 *
 * 將官方 WooCommerce 綠界整合包裝為 WU 模組；服務是否啟用仍完全由
 * WooCommerce 設定頁的付款、物流與發票開關控制。
 */
defined('ABSPATH') || exit;

$wutm_ecpay_root = __DIR__ . '/vendor/ecpay-ecommerce-for-woocommerce/';

// The official implementation must be loaded while plugins are bootstrapping so
// it can register its WooCommerce and checkout-block compatibility declarations.
if (!defined('WOOECPAY_VERSION') && is_readable($wutm_ecpay_root . 'ecpay-ecommerce-for-woocommerce.php')) {
    require_once $wutm_ecpay_root . 'ecpay-ecommerce-for-woocommerce.php';
}

add_action('admin_menu', static function (): void {
    add_submenu_page(
        'wu-toolbox-modular',
        '綠界金流／物流／電子發票工具',
        '綠界金流／物流／電子發票工具',
        'manage_woocommerce',
        'wu-ecpay-tools',
        static function (): void {
            if (!current_user_can('manage_woocommerce')) return;
            $settings_url = admin_url('admin.php?page=wc-settings&tab=wooecpay_setting&section=ecpay_main');
            echo '<div class="wrap"><h1>綠界金流／物流／電子發票工具</h1><div class="card">';
            echo '<p>此模組提供綠界付款、超商／宅配物流與電子發票的 WooCommerce 整合。</p>';
            echo '<p>請分別填入金流、物流及電子發票的 MerchantID、HashKey 與 HashIV；各服務的商店資料不可混用。</p>';
            echo '<p><a class="button button-primary" href="' . esc_url($settings_url) . '">開啟綠界設定</a></p>';
            echo '<p class="description">付款、物流與發票可個別啟用。上線前請先以綠界測試環境確認 CheckMacValue、背景通知網址與訂單回傳。</p>';
            echo '</div></div>';
        }
    );
});

unset($wutm_ecpay_root);
