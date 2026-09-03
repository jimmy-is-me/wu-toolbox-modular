<?php
/**
 * Module: discord-notifications
 *
 * Loads the bundled Discord WooCommerce notification source only when this
 * WU Toolbox module is enabled. The standalone plugin bootstrap is excluded
 * so it cannot be activated twice.
 */
defined('ABSPATH') || exit;

final class WUTM_Discord_Notifications_Module {
    private const SOURCE_PLUGIN = 'discord-sale-notifications-for-woocommerce/wc-sale-discord-notifications.php';
    private static $notice = '';

    public static function boot(): void {
        if (self::source_plugin_is_active()) {
            self::set_notice('已偵測到獨立的 Discord 訂單通知外掛，本模組不會重複載入通知程式。');
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
        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', WUTM_FILE, true);
        }
    }

    public static function initialize(): void {
        global $wp_version;

        if (PHP_VERSION_ID < 80000) {
            self::set_notice('Discord 通知工具需要 PHP 8.0 或更新版本。');
            return;
        }

        if (version_compare((string) $wp_version, '6.2', '<')) {
            self::set_notice('Discord 通知工具需要 WordPress 6.2 或更新版本。');
            return;
        }

        if (!class_exists('WooCommerce')) {
            self::set_notice('Discord 通知工具需要先安裝並啟用 WooCommerce。');
            return;
        }

        $woocommerce_version = defined('WC_VERSION') ? WC_VERSION : (defined('WOOCOMMERCE_VERSION') ? WOOCOMMERCE_VERSION : '0');
        if (version_compare($woocommerce_version, '8.5', '<')) {
            self::set_notice('Discord 通知工具需要 WooCommerce 8.5 或更新版本。');
            return;
        }

        $vendor_file = WUTM_PATH . 'modules/discord-notifications/vendor/discord-notifications.php';
        if (!is_readable($vendor_file)) {
            self::set_notice('Discord 通知工具載入失敗，請重新安裝完整的 WU Toolbox Modular。');
            return;
        }

        require_once $vendor_file;
        if (!class_exists('WUTM_Discord_Sale_Notifications')) {
            self::set_notice('Discord 通知工具載入失敗，請重新安裝完整的 WU Toolbox Modular。');
            return;
        }

        new WUTM_Discord_Sale_Notifications();
    }

    private static function set_notice(string $message): void {
        self::$notice = $message;
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
    }

    public static function admin_notice(): void {
        if (!current_user_can('manage_woocommerce') || self::$notice === '') {
            return;
        }

        echo '<div class="notice notice-warning"><p>' . esc_html(self::$notice) . '</p></div>';
    }
}

WUTM_Discord_Notifications_Module::boot();
