<?php
/**
 * Plugin Name: WU Toolbox Modular
 * Description: WU Toolbox 的按需載入模組化版本。每項功能獨立，只有啟用後才會載入。
 * Version: 1.0.0
 * Author: Wumetax
 * License: GPL-2.0-or-later
 * Text Domain: wu-toolbox-modular
 */

defined('ABSPATH') || exit;

define('WUTM_VERSION', '1.0.0');
define('WUTM_PATH', plugin_dir_path(__FILE__));
define('WUTM_URL', plugin_dir_url(__FILE__));

/* Backward-compatible constants let migrated modules retain their safe URLs. */
defined('WUMETAX_VERSION') || define('WUMETAX_VERSION', WUTM_VERSION);
defined('WUMETAX_PATH') || define('WUMETAX_PATH', WUTM_PATH);
defined('WUMETAX_URL') || define('WUMETAX_URL', WUTM_URL);

require_once WUTM_PATH . 'core/module-registry.php';
require_once WUTM_PATH . 'core/module-loader.php';
require_once WUTM_PATH . 'core/admin-page.php';
require_once WUTM_PATH . 'core/module-menu.php';

register_activation_hook(__FILE__, function () {
    add_option('wutm_activation_redirect', true, '', false);
});

add_action('admin_init', function () {
    if (!get_option('wutm_activation_redirect') || wp_doing_ajax() || is_network_admin()) return;
    delete_option('wutm_activation_redirect');
    if (!isset($_GET['activate-multi'])) {
        wp_safe_redirect(admin_url('admin.php?page=wu-toolbox-modular'));
        exit;
    }
});

add_action('admin_menu', function () {
    add_menu_page(
        __('WU Toolbox Modular', 'wu-toolbox-modular'),
        __('WU Toolbox', 'wu-toolbox-modular'),
        'manage_options',
        'wu-toolbox-modular',
        'wutm_render_admin_page',
        'dashicons-admin-tools',
        80
    );
}, 5);

add_action('wp_ajax_wutm_toggle_module', function () {
    check_ajax_referer('wutm_toggle_module', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'forbidden'], 403);

    $key = sanitize_key(wp_unslash($_POST['module'] ?? ''));
    if (!wutm_get_module($key)) wp_send_json_error(['message' => 'unknown_module'], 400);

    update_option(wutm_module_option($key), !empty($_POST['enabled']) ? 1 : 0, false);
    wp_send_json_success(['module' => $key, 'enabled' => (bool) get_option(wutm_module_option($key))]);
});
