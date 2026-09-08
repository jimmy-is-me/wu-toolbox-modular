<?php
/**
 * Plugin Name: WU Toolbox Modular
 * Plugin URI: https://wumetax.com/
 * Description: WU Toolbox 的按需載入模組化版本。每項功能獨立，只有啟用後才會載入。
 * Version: 1.9.4
 * Author: WUMETAX
 * Author URI: https://wumetax.com/
 * License: GPL-2.0-or-later
 * Text Domain: wu-toolbox-modular
 * Update URI: https://github.com/jimmy-is-me/wu-toolbox-modular
 */

defined('ABSPATH') || exit;

define('WUTM_FILE', __FILE__);
define('WUTM_VERSION', '1.9.4');
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

/** Stable WU-owned settings routes for bundled modules. */
add_action('admin_menu', function (): void {
    if (wutm_is_enabled('tree-page-view')) {
        $tree_module = WUTM_PATH . 'modules/tree-page-view/module.php';
        if (is_readable($tree_module)) require_once $tree_module;
        add_submenu_page('wu-toolbox-modular', '樹狀頁面視圖', '樹狀頁面視圖', 'manage_options', 'wu-tree-page-view', 'wutm_render_tree_page_view_settings');
    }

    if (wutm_is_enabled('cloudflare-turnstile')) {
        $turnstile_module = WUTM_PATH . 'modules/cloudflare-turnstile/module.php';
        if (is_readable($turnstile_module)) require_once $turnstile_module;
        add_submenu_page('wu-toolbox-modular', 'Cloudflare Turnstile', 'Cloudflare Turnstile', 'manage_options', 'wu-cloudflare-turnstile', 'wutm_render_turnstile_settings');
    }
}, 20);

function wutm_render_turnstile_settings(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('您沒有管理此設定的權限。', 'wu-toolbox-modular'));
    $module = WUTM_PATH . 'modules/cloudflare-turnstile/module.php';
    if (is_readable($module)) require_once $module;

    $saved = false;
    if (isset($_POST['wutm_turnstile_save'])) {
        check_admin_referer('wutm_turnstile_settings');
        update_option('cfturnstile_key', sanitize_text_field(wp_unslash($_POST['cfturnstile_key'] ?? '')));
        update_option('cfturnstile_secret', sanitize_text_field(wp_unslash($_POST['cfturnstile_secret'] ?? '')));
        foreach (['cfturnstile_login', 'cfturnstile_register', 'cfturnstile_comment', 'cfturnstile_disable_button'] as $option) {
            update_option($option, isset($_POST[$option]) ? 1 : 0);
        }
        // Keys must be checked again after they are changed.
        update_option('cfturnstile_tested', 'no');
        $saved = true;
    }
    if (isset($_POST['wutm_turnstile_test'])) {
        check_admin_referer('wutm_turnstile_settings');
        $result = function_exists('cfturnstile_verify_secret') ? cfturnstile_verify_secret() : null;
        if ($result === true) {
            update_option('cfturnstile_tested', 'yes');
            $saved = '驗證成功，Turnstile 已可保護已啟用的表單。';
        } elseif ($result === false) {
            $saved = '無法驗證 Secret Key，請檢查 Cloudflare 後台的金鑰。';
        } else {
            $saved = '暫時無法連線至 Cloudflare，請稍後再試。';
        }
    }

    echo '<div class="wrap"><h1>Cloudflare Turnstile</h1>';
    if ($saved) echo '<div class="notice notice-' . ($saved === true ? 'success' : ($saved === '驗證成功，Turnstile 已可保護已啟用的表單。' ? 'success' : 'warning')) . '"><p>' . esc_html($saved === true ? '設定已儲存，請按「測試金鑰」完成啟用。' : $saved) . '</p></div>';
    echo '<p>直接設定 Cloudflare Turnstile 金鑰與要保護的 WordPress 表單。金鑰更新後需測試一次才會啟用驗證。</p><form method="post">';
    wp_nonce_field('wutm_turnstile_settings');
    echo '<table class="form-table"><tr><th><label for="cfturnstile_key">Site Key</label></th><td><input class="regular-text" id="cfturnstile_key" name="cfturnstile_key" value="' . esc_attr((string) get_option('cfturnstile_key')) . '"></td></tr>';
    echo '<tr><th><label for="cfturnstile_secret">Secret Key</label></th><td><input class="regular-text" type="password" id="cfturnstile_secret" name="cfturnstile_secret" value="' . esc_attr((string) get_option('cfturnstile_secret')) . '" autocomplete="new-password"></td></tr>';
    echo '<tr><th>保護表單</th><td>';
    foreach (['cfturnstile_login' => '登入', 'cfturnstile_register' => '註冊', 'cfturnstile_comment' => '留言', 'cfturnstile_disable_button' => '驗證完成前停用送出按鈕'] as $option => $label) {
        echo '<label style="display:block;margin:6px 0"><input type="checkbox" name="' . esc_attr($option) . '" value="1" ' . checked((bool) get_option($option), true, false) . '> ' . esc_html($label) . '</label>';
    }
    echo '</td></tr></table><p><button class="button button-primary" name="wutm_turnstile_save" value="1">儲存設定</button> <button class="button" name="wutm_turnstile_test" value="1">測試金鑰</button></p></form></div>';
}

function wutm_render_tree_page_view_settings(): void {
    if (!current_user_can('manage_options')) wp_die(esc_html__('您沒有管理此設定的權限。', 'wu-toolbox-modular'));
    $module = WUTM_PATH . 'modules/tree-page-view/module.php';
    if (is_readable($module)) require_once $module;
    $current = (array) get_option('cms_tpv_options', ['dashboard' => ['page'], 'menu' => ['page']]);
    $current += ['dashboard' => [], 'menu' => []];
    if (isset($_POST['wutm_tree_save'])) {
        check_admin_referer('wutm_tree_page_view_settings');
        $available = get_post_types(['show_ui' => true], 'names');
        $clean = static function ($values) use ($available): array {
            return array_values(array_intersect($available, array_map('sanitize_key', (array) $values)));
        };
        $current = ['dashboard' => $clean(wp_unslash($_POST['dashboard'] ?? [])), 'menu' => $clean(wp_unslash($_POST['menu'] ?? []))];
        update_option('cms_tpv_options', $current);
        echo '<div class="notice notice-success"><p>樹狀頁面視圖設定已儲存。</p></div>';
    }
    $post_types = get_post_types(['show_ui' => true], 'objects');
    echo '<div class="wrap"><h1>樹狀頁面視圖</h1><p>選擇要提供樹狀檢視的內容類型；啟用「選單」後會在對應後台選單出現樹狀頁面入口。</p><form method="post">';
    wp_nonce_field('wutm_tree_page_view_settings');
    echo '<table class="widefat striped"><thead><tr><th>內容類型</th><th>儀表板</th><th>後台選單</th></tr></thead><tbody>';
    foreach ($post_types as $post_type) {
        if (in_array($post_type->name, ['attachment'], true)) continue;
        $key = $post_type->name;
        echo '<tr><td>' . esc_html($post_type->labels->name) . '</td><td><input type="checkbox" name="dashboard[]" value="' . esc_attr($key) . '" ' . checked(in_array($key, (array) $current['dashboard'], true), true, false) . '></td><td><input type="checkbox" name="menu[]" value="' . esc_attr($key) . '" ' . checked(in_array($key, (array) $current['menu'], true), true, false) . '></td></tr>';
    }
    echo '</tbody></table><p><button class="button button-primary" name="wutm_tree_save" value="1">儲存設定</button> <a class="button" href="' . esc_url(admin_url('edit.php?post_type=page&page=cms-tpv-page-page')) . '">開啟頁面樹狀視圖</a></p></form></div>';
}

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
