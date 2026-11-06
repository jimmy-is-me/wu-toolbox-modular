<?php
/** Menu sharing is presentation, not a replacement for WordPress capabilities. */
defined('ABSPATH') || exit;

function wutm_toolbox_owner_ids(): array {
    $stored = get_option('wutm_toolbox_primary_admin_id', 0);
    if (!is_scalar($stored) || !preg_match('/^[1-9][0-9]*$/D', (string)$stored)) return [];
    $id = (int)$stored;
    $user = get_userdata($id);
    return $user && user_can($user, 'manage_options') ? [$id] : [];
}
function wutm_toolbox_is_owner(): bool {
    return current_user_can('manage_options') && in_array(get_current_user_id(), wutm_toolbox_owner_ids(), true);
}
function wutm_toolbox_hidden(): bool {
    // Old implicit owners/flags must never silently elect an administrator.
    // An invalid/deleted owner reopens access; it does not appoint a replacement.
    return (bool)get_option('wutm_hide_toolbox_other_admins', 0) && (bool)wutm_toolbox_owner_ids();
}
function wutm_toolbox_can_manage(): bool {
    return current_user_can('manage_options') && (!wutm_toolbox_hidden() || wutm_toolbox_is_owner());
}
function wutm_toolbox_shared_modules(): array {
    return array_filter(wutm_modules(), static function ($module, $key): bool {
        return wutm_module_can_share($key, $module) && wutm_module_is_shared($key) && wutm_shared_module_available($key, $module);
    }, ARRAY_FILTER_USE_BOTH);
}
function wutm_toolbox_can_view(): bool {
    return wutm_toolbox_can_manage() || (current_user_can('manage_options') && (bool) wutm_toolbox_shared_modules());
}
function wutm_module_can_share(string $key, array $module): bool {
    return empty($module['navigation']) && ($module['tag'] ?? '') !== '第三方外掛' && !in_array($key, ['admin-menu-editor', 'web-tools'], true);
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
function wutm_toolbox_save_visibility(bool $hide) {
    if (!current_user_can('manage_options') || (wutm_toolbox_owner_ids() && !wutm_toolbox_is_owner())) return new WP_Error('forbidden', '只有主帳號可以變更已啟用的 Toolbox 顯示設定。');
    if ($hide && !wutm_toolbox_is_owner()) {
        // A stale/deleted account is not an owner. First explicit save claims
        // the current administrator, never a submitted ID or menu-editor owner.
        $stale = get_option('wutm_toolbox_primary_admin_id', false);
        if ($stale !== false) {
            if (wutm_toolbox_owner_ids()) return new WP_Error('conflict', '其他管理員已儲存主帳號，請重新整理後確認。');
            global $wpdb;
            // Compare-and-delete only the invalid value we inspected. A new
            // administrator's concurrent claim must never be overwritten.
            if (1 !== $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", 'wutm_toolbox_primary_admin_id', maybe_serialize($stale)))) return new WP_Error('conflict', '設定已變更，請重新整理後再試。');
            foreach (['wutm_toolbox_primary_admin_id','alloptions','notoptions'] as $key) wp_cache_delete($key, 'options');
        }
        if (!add_option('wutm_toolbox_primary_admin_id', get_current_user_id(), '', false)) return new WP_Error('conflict', '其他管理員已儲存主帳號，請重新整理後確認。');
    }
    update_option('wutm_hide_toolbox_other_admins', $hide ? 1 : 0, false);
    if (!$hide) delete_option('wutm_toolbox_primary_admin_id');
    return true;
}
function wutm_toolbox_visibility_request(): void {
    if (!current_user_can('manage_options')) wp_die('沒有管理權限。', '', ['response' => 403]);
    check_admin_referer('wutm_toolbox_visibility');
    $hide = isset($_POST['hide_toolbox']) && $_POST['hide_toolbox'] === '1';
    $result = wutm_toolbox_save_visibility($hide);
    if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()), '', ['response' => 403]);
    wp_safe_redirect(admin_url('admin.php?page=wu-toolbox-modular&visibility_saved=1#wutm-license'));
    exit;
}
add_action('admin_post_wutm_toolbox_visibility', 'wutm_toolbox_visibility_request');

function wutm_render_toolbox_visibility(): void {
    if (!wutm_toolbox_can_manage()) return;
    $owner = wutm_toolbox_owner_ids();
    ?>
    <div class="wutm-license-block wutm-visibility-settings"><h3>Toolbox 顯示與開放</h3>
        <?php if (isset($_GET['visibility_saved']) && $_GET['visibility_saved'] === '1'): ?><p role="status">Toolbox 顯示設定已儲存。</p><?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="wutm_toolbox_visibility"><?php wp_nonce_field('wutm_toolbox_visibility'); ?>
            <label><input type="checkbox" name="hide_toolbox" value="1" <?php checked(wutm_toolbox_hidden()); ?>> 向其他管理員隱藏 WumetaxToolkit</label>
            <p class="description">預設不開啟。勾選並儲存後，以目前登入的管理員作為 Toolbox 主帳號，與「後台介面管理」及「後台選單編輯器」的主帳號設定無關。其他管理員僅能看到已開放且啟用的模組；沒有開放模組時不顯示 Toolbox。這不會變更 WordPress 角色與權限。</p>
            <?php if ($owner): $user = get_userdata($owner[0]); ?><p>目前主帳號：<?php echo esc_html(($user->display_name ?? $user->user_login ?? '') . '（ID：' . $owner[0] . '）'); ?></p><?php endif; ?>
            <p class="submit"><button type="submit" class="button button-primary">儲存</button> <button type="reset" class="button">取消</button></p>
            <p class="description">「取消」還原未儲存的修改；若要停止隱藏，取消勾選後按「儲存」，即可解除主帳號指定並恢復開放。</p>
        </form>
    </div>
    <?php
}

/** One Toolbox dashboard; retain original registered page hooks. */
add_action('admin_menu', function (): void {
    if (!current_user_can('manage_options')) return;
    global $submenu;
    if (!wutm_toolbox_can_manage()) {
        if (!wutm_toolbox_can_view()) { remove_menu_page('wu-toolbox-modular'); return; }
        $submenu['wu-toolbox-modular'] = array_values(array_filter($submenu['wu-toolbox-modular'] ?? [], static function ($entry): bool {
            $slug = (string) ($entry[2] ?? '');
            if ($slug === 'wu-toolbox-modular' || wutm_is_shared_menu_target($slug)) return true;
            foreach (wutm_toolbox_shared_modules() as $module) if (in_array($slug, (array) ($module['related_pages'] ?? []), true)) return true;
            return false;
        }));
    }
}, PHP_INT_MAX);
