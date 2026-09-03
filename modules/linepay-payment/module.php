<?php
/**
 * Module: linepay-payment
 *
 * Loads the Taiwan LINE Pay WooCommerce gateway from the bundled GPL source.
 */
defined('ABSPATH') || exit;

final class WUTM_LINEPay_Payment_Module {
    private const SOURCE_PLUGIN = 'wpbr-linepay-tw/wpbr-linepay-tw.php';
    private const MINIMUM_PHP = '8.0';
    private const MINIMUM_WOOCOMMERCE = '6.0.0';
    private static string $notice = '';

    public static function boot(): void {
        if (self::source_plugin_is_active()) {
            self::$notice = '已偵測到獨立的 LINE Pay 付款外掛，本模組不會重複載入付款程式。';
            add_action('admin_notices', [__CLASS__, 'admin_notice']);
            return;
        }

        if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
            self::$notice = 'LINE Pay 付款工具需要 PHP 8.0 或更新版本。';
            add_action('admin_notices', [__CLASS__, 'admin_notice']);
            return;
        }

        add_action('before_woocommerce_init', [__CLASS__, 'declare_compatibility']);
        add_action('woocommerce_blocks_loaded', [__CLASS__, 'register_block_checkout']);
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
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                WUTM_FILE,
                true
            );
        }
    }

    public static function initialize(): void {
        if (!class_exists('WooCommerce')) {
            self::$notice = 'LINE Pay 付款工具需要先安裝並啟用 WooCommerce。';
            add_action('admin_notices', [__CLASS__, 'admin_notice']);
            return;
        }

        if (defined('WC_VERSION') && version_compare(WC_VERSION, self::MINIMUM_WOOCOMMERCE, '<')) {
            self::$notice = 'LINE Pay 付款工具需要 WooCommerce 6.0 或更新版本。';
            add_action('admin_notices', [__CLASS__, 'admin_notice']);
            return;
        }

        self::define_constants();
        require_once WUTM_LINEPAY_PLUGIN_DIR . 'includes/class-wpbr-linepay-tw.php';

        if (!class_exists('LINEPay_TW')) {
            self::$notice = 'LINE Pay 付款工具載入失敗，請重新安裝完整的 WU Toolbox Modular。';
            add_action('admin_notices', [__CLASS__, 'admin_notice']);
            return;
        }

        \LINEPay_TW::init();
    }

    public static function register_block_checkout(): void {
        if (
            !class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')
            || !class_exists('Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry')
        ) {
            return;
        }

        self::define_constants();
        require_once WUTM_LINEPAY_PLUGIN_DIR . 'includes/utils/class-wpbr-linepay-const.php';
        require_once WUTM_LINEPAY_PLUGIN_DIR . 'includes/gateways/class-linepay-tw-payment-block.php';

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            static function ($payment_method_registry): void {
                if (class_exists('LINEPay_Block')) {
                    $payment_method_registry->register(new \LINEPay_Block());
                }
            }
        );
    }

    private static function define_constants(): void {
        defined('WUTM_LINEPAY_PLUGIN_URL') || define(
            'WUTM_LINEPAY_PLUGIN_URL',
            WUTM_URL . 'modules/linepay-payment/vendor/'
        );
        defined('WUTM_LINEPAY_PLUGIN_DIR') || define(
            'WUTM_LINEPAY_PLUGIN_DIR',
            WUTM_PATH . 'modules/linepay-payment/vendor/'
        );
        defined('WUTM_LINEPAY_BASENAME') || define(
            'WUTM_LINEPAY_BASENAME',
            'wu-toolbox-modular/modules/linepay-payment/vendor/wpbr-linepay-tw.php'
        );
        defined('WUTM_LINEPAY_VERSION') || define('WUTM_LINEPAY_VERSION', '1.3.3');
    }

    public static function admin_notice(): void {
        if (!current_user_can('manage_woocommerce') || self::$notice === '') {
            return;
        }

        echo '<div class="notice notice-warning"><p>' . esc_html(self::$notice) . '</p></div>';
    }
}

WUTM_LINEPay_Payment_Module::boot();
