<?php
/**
 * WU Toolbox Modular module: floating contact buttons.
 * Provides configurable contact links (LINE, Messenger, WhatsApp, phone, email and more).
 */
defined('ABSPATH') || exit;

define('WUTM_CONTACT_WIDGETS_VERSION', '1.2.4');
define('WUTM_CONTACT_WIDGETS_PLUGIN_FILE', __FILE__);
define('WUTM_CONTACT_WIDGETS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WUTM_CONTACT_WIDGETS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WUTM_CONTACT_WIDGETS_BASENAME', plugin_basename(__FILE__));

spl_autoload_register(function ($class) {
    $prefix = 'WUTM\\ContactWidgets\\';
    $base = WUTM_CONTACT_WIDGETS_PLUGIN_DIR . 'src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) return;
    $file = $base . str_replace('\\', '/', substr($class, $len)) . '.php';
    if (is_readable($file)) require_once $file;
});

try {
    if (class_exists('WUTM\\ContactWidgets\\YSChatWidgets')) {
        \WUTM\ContactWidgets\YSChatWidgets::instance();
    }
} catch (\Throwable $e) {
    // A broken optional module must never take down WordPress.
    error_log('WU Toolbox Modular contact-widgets: ' . $e->getMessage());
    add_action('admin_notices', static function () {
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('浮動聯絡按鈕模組載入失敗，請檢查外掛檔案。', 'wu-contact-widgets') . '</p></div>';
        }
    });
}
