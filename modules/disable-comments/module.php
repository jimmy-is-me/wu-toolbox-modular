<?php
/**
 * Disable Comments 模組
 *
 * 僅提供停用留言及移除後台留言入口，不包含教學、廣告、留言類型
 * 或個人頭像設定。
 */
defined('ABSPATH') || exit;

final class WUTM_Disable_Comments {
    private const OPTION = 'wutm_disable_comments_settings';

    public static function boot(): void {
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_menu', [__CLASS__, 'remove_admin_menus'], 100);
        add_action('wp_before_admin_bar_render', [__CLASS__, 'remove_admin_bar_item']);
        add_filter('comments_open', [__CLASS__, 'comments_open'], 20, 2);
        add_filter('pings_open', [__CLASS__, 'pings_open'], 20, 2);
        add_filter('comments_array', [__CLASS__, 'hide_comments'], 20, 2);
        add_filter('rest_endpoints', [__CLASS__, 'remove_rest_comments']);
        add_action('admin_post_wutm_disable_comments_save', [__CLASS__, 'save_settings']);
        add_action('admin_post_wutm_disable_comments_delete_all', [__CLASS__, 'delete_all_comments']);
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 30);
    }

    private static function enabled(): bool {
        $settings = get_option(self::OPTION, ['enabled' => 1]);
        return !empty($settings['enabled']);
    }

    public static function register_settings(): void {
        register_setting('wutm_disable_comments', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => static function ($value): array {
                return ['enabled' => empty($value['enabled']) ? 0 : 1];
            },
            'default' => ['enabled' => 1],
        ]);
    }

    public static function comments_open($open, $post_id) {
        return self::enabled() ? false : $open;
    }

    public static function pings_open($open, $post_id) {
        return self::enabled() ? false : $open;
    }

    public static function hide_comments($comments, $post_id) {
        return self::enabled() ? [] : $comments;
    }

    public static function remove_rest_comments($endpoints): array {
        if (!self::enabled() || !is_array($endpoints)) return $endpoints;
        foreach (array_keys($endpoints) as $route) {
            if (strpos($route, '/comments') !== false) unset($endpoints[$route]);
        }
        return $endpoints;
    }

    public static function remove_admin_menus(): void {
        if (!self::enabled() || !current_user_can('manage_options')) return;
        remove_menu_page('edit-comments.php');
        remove_submenu_page('options-general.php', 'options-discussion.php');
    }

    public static function remove_admin_bar_item(): void {
        if (!self::enabled() || !is_admin_bar_showing()) return;
        global $wp_admin_bar;
        if (is_object($wp_admin_bar)) $wp_admin_bar->remove_node('comments');
    }

    public static function admin_menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '留言停用',
            '留言停用',
            'manage_options',
            'wu-disable-comments',
            [__CLASS__, 'render_page']
        );
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('wutm_disable_comments_save')) wp_die('安全驗證失敗', 403);
        update_option(self::OPTION, ['enabled' => empty($_POST['enabled']) ? 0 : 1], false);
        wp_safe_redirect(add_query_arg(['page' => 'wu-disable-comments', 'updated' => 1], admin_url('admin.php')));
        exit;
    }

    public static function delete_all_comments(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('wutm_disable_comments_delete_all')) wp_die('安全驗證失敗', 403);
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->comments}");
        wp_safe_redirect(add_query_arg(['page' => 'wu-disable-comments', 'deleted' => 1], admin_url('admin.php')));
        exit;
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $settings = get_option(self::OPTION, ['enabled' => 1]);
        $enabled = !empty($settings['enabled']);
        $comment_count = (int) wp_count_comments()->total_comments;
        ?>
        <div class="wrap wutm-module-wrap">
            <header class="wutm-header"><div><h1>🚫 留言停用</h1><p>停用網站留言功能並移除後台留言入口。</p></div><span><?php echo $enabled ? '運作中' : '已停用'; ?></span></header>
            <?php if (!empty($_GET['updated'])): ?><div class="notice notice-success"><p>留言設定已儲存。</p></div><?php endif; ?>
            <?php if (!empty($_GET['deleted'])): ?><div class="notice notice-success"><p>現有留言已刪除。</p></div><?php endif; ?>
            <section class="wu-info-panel">
                <h2>留言功能狀態</h2>
                <p><strong><?php echo $enabled ? '已停用留言' : '留言功能開放'; ?></strong></p>
                <p class="description"><?php echo $enabled ? '前台留言、Pingback、REST 留言端點與後台留言入口皆已停用。' : '啟用後將停用留言相關功能。'; ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wutm_disable_comments_save">
                    <?php wp_nonce_field('wutm_disable_comments_save'); ?>
                    <label><input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>> 停用留言功能</label>
                    <?php submit_button('儲存設定', 'primary', 'submit', false); ?>
                </form>
            </section>
            <section class="wu-info-panel">
                <h2>現有留言</h2>
                <p>目前資料庫共有 <?php echo esc_html((string) $comment_count); ?> 則留言。</p>
                <p class="description">刪除留言是不可逆操作，執行前請先備份資料庫。</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('確定要刪除全部留言嗎？此操作無法復原。');">
                    <input type="hidden" name="action" value="wutm_disable_comments_delete_all">
                    <?php wp_nonce_field('wutm_disable_comments_delete_all'); ?>
                    <?php submit_button('刪除全部留言', 'secondary', 'submit', false); ?>
                </form>
            </section>
        </div>
        <?php
    }
}
WUTM_Disable_Comments::boot();
