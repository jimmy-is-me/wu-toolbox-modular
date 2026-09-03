<?php
/**
 * Module: payuni-payment
 *
 * Loads the PAYUNi WooCommerce gateway from the bundled GPL source only when
 * this module is enabled. The upstream standalone bootstrap is intentionally
 * replaced to prevent duplicate plugin activation and global function clashes.
 */
defined('ABSPATH') || exit;

final class WUTM_PAYUNi_Payment_Module {
    private const SOURCE_PLUGIN = 'wpbr-payuni-payment/wpbr-payuni-payment.php';
    private static string $notice = '';

    public static function boot(): void {
        if (self::source_plugin_is_active()) {
            self::$notice = '已偵測到獨立的 PAYUNi 付款外掛，本模組不會重複載入付款程式。';
            add_action('admin_notices', [__CLASS__, 'admin_notice']);
            return;
        }

        add_action('before_woocommerce_init', [__CLASS__, 'declare_compatibility']);
        add_action('plugins_loaded', [__CLASS__, 'initialize'], 20);
    }

    private static function source_plugin_is_active(): bool {
        $active = (array) get_option('active_plugins', []);
        $network_active = is_multisite() ? (array) get_site_option('active_sitewide_plugins', []) : [];

        return in_array(self::SOURCE_PLUGIN, $active, true) || isset($network_active[self::SOURCE_PLUGIN]);
    }

    public static function declare_compatibility(): void {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                WUTM_FILE,
                true
            );
        }
    }

    public static function initialize(): void {
        if (!class_exists('WooCommerce')) {
            self::$notice = 'PAYUNi 付款工具需要先安裝並啟用 WooCommerce。';
            add_action('admin_notices', [__CLASS__, 'admin_notice']);
            return;
        }

        if (!function_exists('openssl_encrypt') || !function_exists('openssl_decrypt')) {
            self::$notice = 'PAYUNi 付款工具需要伺服器啟用 OpenSSL 擴充套件。';
            add_action('admin_notices', [__CLASS__, 'admin_notice']);
            return;
        }

        self::define_constants();
        require_once WUTM_PAYUNI_PLUGIN_DIR . 'vendor/autoload.php';

        if (!class_exists('\WPBrewer\Payuni\Payment\PayuniPayment')) {
            self::$notice = 'PAYUNi 付款工具載入失敗，請重新安裝完整的 WU Toolbox Modular。';
            add_action('admin_notices', [__CLASS__, 'admin_notice']);
            return;
        }

        \WPBrewer\Payuni\Payment\PayuniPayment::init();
    }

    private static function define_constants(): void {
        defined('WUTM_PAYUNI_PLUGIN_URL') || define(
            'WUTM_PAYUNI_PLUGIN_URL',
            WUTM_URL . 'modules/payuni-payment/vendor/'
        );
        defined('WUTM_PAYUNI_PLUGIN_DIR') || define(
            'WUTM_PAYUNI_PLUGIN_DIR',
            WUTM_PATH . 'modules/payuni-payment/vendor/'
        );
        defined('WUTM_PAYUNI_BASENAME') || define(
            'WUTM_PAYUNI_BASENAME',
            'wu-toolbox-modular/modules/payuni-payment/vendor/wpbr-payuni-payment.php'
        );
        defined('WUTM_PAYUNI_PAYMENT_VERSION') || define('WUTM_PAYUNI_PAYMENT_VERSION', '1.8.1');
    }

    public static function admin_notice(): void {
        if (!current_user_can('manage_woocommerce') || self::$notice === '') {
            return;
        }

        echo '<div class="notice notice-warning"><p>' . esc_html(self::$notice) . '</p></div>';
    }
}

WUTM_PAYUNi_Payment_Module::boot();
