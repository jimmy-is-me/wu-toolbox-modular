<?php
defined('ABSPATH') || exit;

function wutm_role_manager_protected_roles(): array {
    return ['administrator', 'editor', 'author', 'contributor', 'subscriber', 'customer', 'shop_manager'];
}

function wutm_role_manager_page_url(array $args = []): string {
    return add_query_arg($args, admin_url('admin.php?page=wu-role-cleanup-manager'));
}

add_action('admin_menu', function (): void {
    add_submenu_page(
        'wu-toolbox-modular',
        '使用者角色管理與清理',
        '角色清理管理',
        'manage_options',
        'wu-role-cleanup-manager',
        'wutm_render_role_cleanup_manager_page'
    );
});

add_action('admin_post_wutm_role_manager', function (): void {
    if (!current_user_can('manage_options')) wp_die('權限不足。');
    check_admin_referer('wutm_role_manager_action');

    global $wp_roles;
    $roles = is_object($wp_roles) ? $wp_roles->roles : [];
    $operation = sanitize_key(wp_unslash($_POST['operation'] ?? ''));
    $source = sanitize_key(wp_unslash($_POST['source_role'] ?? ''));
    $target = sanitize_key(wp_unslash($_POST['target_role'] ?? ''));
    $protected = wutm_role_manager_protected_roles();
    $notice = 'invalid';
    $count = 0;

    if ($operation === 'migrate') {
        if ($source === 'administrator') {
            $notice = 'administrator';
        } elseif ($source && $target && $source !== $target && isset($roles[$source], $roles[$target])) {
            $user_ids = get_users(['role' => $source, 'fields' => 'ids']);
            foreach ($user_ids as $user_id) {
                $user = get_user_by('id', $user_id);
                if (!$user) continue;
                // Add first so a user is never left without a valid role.
                $user->add_role($target);
                $user->remove_role($source);
                $count++;
            }
            $notice = 'migrated';
        }
    } elseif ($operation === 'delete') {
        if (in_array($source, $protected, true)) {
            $notice = 'protected';
        } elseif ($source && isset($roles[$source])) {
            $has_users = get_users(['role' => $source, 'fields' => 'ids', 'number' => 1]);
            if ($has_users) {
                $notice = 'not_empty';
            } else {
                remove_role($source);
                $notice = 'deleted';
            }
        }
    }

    wp_safe_redirect(wutm_role_manager_page_url([
        'wutm_role_notice' => $notice,
        'wutm_role_count' => $count,
    ]));
    exit;
});

function wutm_render_role_cleanup_manager_page(): void {
    if (!current_user_can('manage_options')) return;
    global $wp_roles;
    $all_roles = is_object($wp_roles) ? $wp_roles->roles : [];
    $protected = wutm_role_manager_protected_roles();
    $counts = count_users();
    $role_counts = is_array($counts['avail_roles'] ?? null) ? $counts['avail_roles'] : [];
    $notice = sanitize_key(wp_unslash($_GET['wutm_role_notice'] ?? ''));
    $migrated = absint($_GET['wutm_role_count'] ?? 0);
    $messages = [
        'migrated' => [$migrated . ' 位使用者已完成角色遷移。', 'success'],
        'deleted' => ['自訂角色已刪除。', 'success'],
        'not_empty' => ['此角色仍有使用者，請先遷移成員後再刪除。', 'error'],
        'protected' => ['WordPress 或 WooCommerce 核心角色禁止刪除。', 'error'],
        'administrator' => ['為避免管理員全部失去網站權限，不允許批次遷移 administrator。', 'error'],
        'invalid' => ['操作資料無效或角色已不存在，未進行任何變更。', 'error'],
    ];
    ?>
    <div class="wrap">
        <h1>使用者角色管理與清理</h1>
        <p class="wutm-module-subtitle">檢查外掛停用後留下的角色、遷移成員，並刪除沒有使用者的自訂角色。</p>
        <?php if (isset($messages[$notice])): ?>
            <div class="notice notice-<?php echo esc_attr($messages[$notice][1]); ?> is-dismissible"><p><?php echo esc_html($messages[$notice][0]); ?></p></div>
        <?php endif; ?>
        <div class="notice notice-warning inline"><p><strong>操作前建議先備份資料庫。</strong>角色遷移會變更該角色所有使用者的權限；角色刪除後無法自動復原。</p></div>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>顯示名稱</th><th>角色代碼</th><th style="width:80px">人數</th><th>成員（最多 10 位）</th><th style="width:360px">操作</th></tr></thead>
            <tbody>
            <?php foreach ($all_roles as $role_key => $role_data):
                $user_count = absint($role_counts[$role_key] ?? 0);
                $sample_ids = get_users(['role' => $role_key, 'fields' => 'ids', 'number' => 10]);
                $names = [];
                foreach ($sample_ids as $user_id) {
                    $user = get_user_by('id', $user_id);
                    if ($user) $names[] = $user->user_login;
                }
                ?>
                <tr>
                    <td><strong><?php echo esc_html(translate_user_role($role_data['name'])); ?></strong><?php if (in_array($role_key, $protected, true)): ?> <span class="wutm-badge">核心保護</span><?php endif; ?></td>
                    <td><code><?php echo esc_html($role_key); ?></code></td>
                    <td><?php echo esc_html((string)$user_count); ?></td>
                    <td><?php echo $names ? esc_html(implode(', ', $names)) . ($user_count > 10 ? '…' : '') : '<span class="description">無使用者</span>'; ?></td>
                    <td>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-flex;gap:6px;align-items:center;margin:0 8px 5px 0">
                            <input type="hidden" name="action" value="wutm_role_manager">
                            <input type="hidden" name="operation" value="migrate">
                            <input type="hidden" name="source_role" value="<?php echo esc_attr($role_key); ?>">
                            <?php wp_nonce_field('wutm_role_manager_action'); ?>
                            <select name="target_role" required aria-label="遷移目標角色">
                                <option value="">遷移至…</option>
                                <?php foreach ($all_roles as $target_key => $target_data): if ($target_key === $role_key) continue; ?>
                                    <option value="<?php echo esc_attr($target_key); ?>"><?php echo esc_html(translate_user_role($target_data['name'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="button" <?php disabled($user_count === 0 || $role_key === 'administrator'); ?> onclick="return confirm('確定遷移此角色的全部成員？')">遷移成員</button>
                        </form>
                        <?php if (!in_array($role_key, $protected, true)): ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin:0" onsubmit="return confirm('確定永久刪除此角色？此操作無法還原。')">
                                <input type="hidden" name="action" value="wutm_role_manager">
                                <input type="hidden" name="operation" value="delete">
                                <input type="hidden" name="source_role" value="<?php echo esc_attr($role_key); ?>">
                                <?php wp_nonce_field('wutm_role_manager_action'); ?>
                                <button class="button button-link-delete" <?php disabled($user_count > 0); ?>>刪除角色</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
