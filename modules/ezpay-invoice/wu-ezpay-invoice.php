<?php
/**
 * Plugin Name: wu EZPAY 電子發票（獨立版）
 * Description: WooCommerce 電子發票外掛。
 * Version: 1.0.1
 * Author: wu
 * License: GPL-2.0-or-later
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: wu
 * Domain Path: /languages
 */
namespace WuStandaloneEzpay;
defined( 'ABSPATH' ) || exit;

$wutm_invoice_boot = static function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        \add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-error"><p>wu EZPAY 電子發票需要先啟用 WooCommerce。</p></div>';
        } );
        return;
    }
    // The upstream plugin owns the same classes, constants and order metadata.
    if ( defined( 'WOOMP_PLUGIN_DIR' ) || defined( 'EZPAYINVOICE_PLUGIN_DIR' ) ) {
        \add_action( 'admin_notices', static function () {
            echo '<div class="notice notice-error"><p>wu EZPAY 電子發票獨立版未載入：請先停用其他相同發票模組。</p></div>';
        } );
        return;
    }
    define( 'EZPAYINVOICE_PLUGIN_URL', \plugin_dir_url( __FILE__ ) );
    define( 'EZPAYINVOICE_PLUGIN_DIR', \plugin_dir_path( __FILE__ ) );
    define( 'EZPAYINVOICE_PLUGIN_BASENAME', \plugin_basename( __FILE__ ) );
    \load_plugin_textdomain( 'wu', false, dirname( \plugin_basename( __FILE__ ) ) . '/languages' );
    \add_filter( 'woocommerce_get_settings_pages', static function ( $pages ) {
        $pages[] = \wutm_invoice_settings_page('ezpay');
        return $pages;
    } );
    if ( ! \wc_string_to_bool( \get_option( 'wc_woomp_enabled_ezpay_invoice', 'no' ) ) ) { return; }
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/src/apis/EzPayInvoiceFields.php';
    require_once __DIR__ . '/src/apis/EzPayInvoiceHandler.php';
    require_once __DIR__ . '/src/posts/ShopOrder/Admin.php';
    require_once __DIR__ . '/src/posts/ShopOrder/Ajax.php';
    require_once __DIR__ . '/src/posts/ShopOrder/Metabox.php';
    require_once __DIR__ . '/src/templates/Checkout.php';
    require_once __DIR__ . '/src/templates/MyAccount.php';
};
$wutm_invoice_boot();
unset($wutm_invoice_boot);
