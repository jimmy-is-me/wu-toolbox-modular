<?php
/** Menu sharing is presentation, not a replacement for WordPress capabilities. */
defined('ABSPATH') || exit;

function wutm_toolbox_owner_ids(): array {
    $ids = wutm_admin_menu_editor_owner_ids();
    $legacy = (int) get_option('wu_hide_wumetax_toolkit_admin_id', 0);
    $user = $legacy ? get_userdata($legacy) : false;
    if ($user && user_can($user, 'manage_options')) $ids[] = $legacy;
    // Recovery when an explicitly configured owner has been deleted/demoted.
    if (!$ids) {
        $admins = get_users(['role' => 'administrator', 'orderby' => 'ID', 'order' => 'ASC', 'number' => 1]);
        if ($admins && user_can($admins[0], 'manage_options')) $ids[] = (int) $admins[0]->ID;
    }
    return array_values(array_unique($ids));
}
function wutm_toolbox_is_owner(): bool {
    return current_user_can('manage_options') && in_array(get_current_user_id(), wutm_toolbox_owner_ids(), true);
}
function wutm_toolbox_hidden(): bool { return (bool) get_option('wutm_hide_toolbox_other_admins', 1); }
function wutm_toolbox_can_manage(): bool {
    return current_user_can('manage_options') && (!wutm_toolbox_hidden() || wutm_toolbox_is_owner());
}
function wutm_module_can_share(string $key, array $module): bool {
    return empty($module['navigation']) && ($module['tag'] ?? '') !== '第三方外掛' && $key !== 'admin-menu-editor';
}
function wutm_module_is_shared(string $key): bool {
    $shared = get_option('wutm_shared_module_menus', []);
    return is_array($shared) && !empty($shared[$key]);
}
function wutm_shared_module_available(string $key, array $module): bool {
    if (!wutm_is_enabled($key)) return false;
    if (!empty($module['requires_module']) && !wutm_is_enabled((string) $module['requires_module'])) return false;
    if (($module['requires'] ?? '') === 'woocommerce' && !class_exists('WooCommerce')) return false;
    if (($module['requires'] ?? '') === 'translatepress' && !class_exists('TRP_Translate_Press')) return false;
    return true;
}
/** Explicit sharing takes precedence over an older menu-editor hide rule. */
function wutm_is_shared_menu_target(string $slug): bool {
    foreach (wutm_modules() as $key => $module) {
        if (!wutm_module_can_share($key, $module) || !wutm_module_is_shared($key) || !wutm_shared_module_available($key, $module)) continue;
        $target = (string) ($module['settings_url'] ?? $module['settings_page'] ?? 'wu-' . $key);
        if ($target === $slug) return true;
        $args = [];
        parse_str((string) parse_url($target, PHP_URL_QUERY), $args);
        if (!empty($args['page']) && $args['page'] === $slug) return true;
    }
    return false;
}
function wutm_render_module_share(string $key, array $module, bool $enabled): void {
    if (!wutm_toolbox_is_owner() || !wutm_module_can_share($key, $module)) return;
    echo '<label class="wutm-share-control"><input type="checkbox" class="wutm-share-toggle" data-share-module="' . esc_attr($key) . '" ' . (wutm_module_is_shared($key) ? 'checked ' : '') . (!$enabled ? 'disabled ' : '') . '> <span>開放給其他管理員使用-出現在主選單</span></label>';
}
add_action('wp_ajax_wutm_share_module', function (): void {
    check_ajax_referer('wutm_share_module', 'nonce');
    if (!wutm_toolbox_is_owner()) wp_send_json_error(['message' => '只有主帳號可以設定模組開放。'], 403);
    $key = sanitize_key(wp_unslash($_POST['module'] ?? ''));
    $module = wutm_get_module($key);
    if (!$module || !wutm_module_can_share($key, $module)) wp_send_json_error(['message' => '此項目不支援獨立開放。'], 400);
    $enabled = !empty($_POST['enabled']);
    if ($enabled && !wutm_shared_module_available($key, $module)) wp_send_json_error(['message' => '請先啟用此模組及其必要功能，再開放給其他管理員。'], 400);
    $shared = get_option('wutm_shared_module_menus', []);
    if (!is_array($shared)) $shared = [];
    if ($enabled) $shared[$key] = 1; else unset($shared[$key]);
    update_option('wutm_shared_module_menus', $shared, false);
    wp_send_json_success(['enabled' => $enabled]);
});
add_action('admin_post_wutm_toolbox_visibility', function (): void {
    if (!wutm_toolbox_is_owner()) wp_die('只有主帳號可以設定 Toolbox 顯示方式。', '', ['response' => 403]);
    check_admin_referer('wutm_toolbox_visibility');
    update_option('wutm_hide_toolbox_other_admins', empty($_POST['hide_toolbox']) ? 0 : 1, false);
    wp_safe_redirect(admin_url('admin.php?page=wu-toolbox-modular#wutm-license'));
    exit;
});

/** Keep the original registered page hook and callback, including its assets/load hooks.
 * Only add a native menu link: no duplicate callback, redirect or AJAX request.
 */
add_action('admin_menu', function (): void {
    if (!current_user_can('manage_options')) return;
    global $menu;
    if (!wutm_toolbox_is_owner()) {
        foreach (wutm_modules() as $key => $module) {
            if (!wutm_module_can_share($key, $module) || !wutm_shared_module_available($key, $module) || !wutm_module_is_shared($key)) continue;
            $url = !empty($module['settings_url']) ? (string) $module['settings_url'] : 'admin.php?page=' . ($module['settings_page'] ?? 'wu-' . $key);
            // Registry is trusted, but keep navigation on this site's admin paths.
            if (strpos($url, '://') !== false || strpos($url, '//') === 0) continue;
            $duplicate = false;
            foreach ((array) $menu as $entry) if (($entry[2] ?? '') === $url || ($entry[2] ?? '') === ($module['settings_page'] ?? 'wu-' . $key)) $duplicate = true;
            if (!$duplicate) $menu[] = [$module['name'], 'manage_options', $url, $module['name'], 'menu-top', 'wutm-shared-' . $key, 'dashicons-admin-generic'];
        }
        if (wutm_toolbox_hidden()) remove_menu_page('wu-toolbox-modular');
    }
}, PHP_INT_MAX);
