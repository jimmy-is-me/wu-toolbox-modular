<?php
/**
 * Plugin Name: WU Toolbox Modular
 * Plugin URI: https://wumetax.com/
 * Description: WU Toolbox 的按需載入模組化版本。每項功能獨立，只有啟用後才會載入。
 * Version: 1.7.2
 * Author: WUMETAX
 * Author URI: https://wumetax.com/
 * License: GPL-2.0-or-later
 * Text Domain: wu-toolbox-modular
 * Update URI: https://github.com/jimmy-is-me/wu-toolbox-modular
 */

defined('ABSPATH') || exit;

define('WUTM_FILE', __FILE__);
define('WUTM_VERSION', '1.7.2');
define('WUTM_PATH', plugin_dir_path(__FILE__));
define('WUTM_URL', plugin_dir_url(__FILE__));

defined('WUMETAX_VERSION') || define('WUMETAX_VERSION', WUTM_VERSION);
defined('WUMETAX_PATH') || define('WUMETAX_PATH', WUTM_PATH);
defined('WUMETAX_URL') || define('WUMETAX_URL', WUTM_URL);


add_filter('plugin_action_links_' . plugin_basename(WUTM_FILE), function (array $links): array {
    if (current_user_can('manage_options')) {
        array_unshift(
            $links,
            '<a href="' . esc_url(admin_url('admin.php?page=wu-toolbox-modular')) . '">' .
            esc_html__('設定', 'wu-toolbox-modular') .
            '</a>'
        );
    }
    return $links;
});

add_filter('plugin_row_meta', function (array $links, string $file): array {
    if ($file !== plugin_basename(WUTM_FILE)) return $links;

    $details_url = add_query_arg([
        'tab' => 'plugin-information',
        'plugin' => 'wu-toolbox-modular',
        'TB_iframe' => 'true',
        'width' => '772',
        'height' => '620',
    ], network_admin_url('plugin-install.php'));

    $has_details_link = false;
    $has_website_link = false;
    foreach ($links as $link) {
        $plain_text = wp_strip_all_tags($link);
        if (
            strpos($link, 'open-plugin-details-modal') !== false
            || strpos($link, 'plugin-information') !== false
            || strpos($plain_text, '檢視詳細資料') !== false
        ) {
            $has_details_link = true;
        }
        if (strpos($link, 'wumetax.com') !== false) {
            $has_website_link = true;
        }
    }

    if (!$has_details_link) {
        $links[] = '<a href="' . esc_url($details_url) . '" class="thickbox open-plugin-details-modal" aria-label="' .
            esc_attr__('檢視 WU Toolbox Modular 詳細資料', 'wu-toolbox-modular') . '">' .
            esc_html__('檢視詳細資料', 'wu-toolbox-modular') .
            '</a>';
    }
    if (!$has_website_link) {
        $links[] = '<a href="https://wumetax.com/" target="_blank" rel="noopener noreferrer">' .
            esc_html__('造訪外掛網站', 'wu-toolbox-modular') .
            '</a>';
    }

    return $links;
}, 10, 2);

require_once WUTM_PATH . 'core/module-registry.php';
// License manager is intentionally kept in core/ for future use, but is disabled for now.
require_once WUTM_PATH . 'core/module-loader.php';
require_once WUTM_PATH . 'core/admin-page.php';
require_once WUTM_PATH . 'core/module-menu.php';
require_once WUTM_PATH . 'core/github-release-updater.php';

register_activation_hook(__FILE__, function () {
    add_option('wutm_activation_redirect', true, '', false);
});
register_deactivation_hook(__FILE__, function () {});

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
