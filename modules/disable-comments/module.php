<?php
/**
 * Module: disable-comments
 * Complete site-wide comment shutdown without third-party promotions.
 */
defined('ABSPATH') || exit;

final class WUTM_Disable_Comments {
    private const OPTION = 'wutm_disable_comments_settings';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 30);
        add_action('admin_menu', [__CLASS__, 'remove_comment_menus'], 999);
        add_action('admin_init', [__CLASS__, 'lock_admin_comments']);
        add_action('wp_before_admin_bar_render', [__CLASS__, 'remove_admin_bar_item'], 999);
        add_action('wp_dashboard_setup', [__CLASS__, 'remove_dashboard_widgets'], 999);
        add_action('widgets_init', [__CLASS__, 'remove_comment_widgets'], 99);
        add_action('init', [__CLASS__, 'disable_post_type_support'], 999);
        add_action('template_redirect', [__CLASS__, 'disable_comment_feed']);
        add_action('pre_comment_on_post', [__CLASS__, 'block_comment_submission']);
        add_action('admin_post_wutm_disable_comments_save', [__CLASS__, 'save_settings']);
        add_action('admin_post_wutm_disable_comments_delete_all', [__CLASS__, 'delete_all_comments']);

        add_filter('comments_open', [__CLASS__, 'comments_open'], 99, 2);
        add_filter('pings_open', [__CLASS__, 'comments_open'], 99, 2);
        add_filter('comments_array', [__CLASS__, 'hide_comments'], 99, 2);
        add_filter('comments_template', [__CLASS__, 'empty_comment_template'], 99);
        add_filter('rest_endpoints', [__CLASS__, 'remove_rest_comments']);
        add_filter('xmlrpc_methods', [__CLASS__, 'remove_xmlrpc_methods']);
        add_filter('wp_headers', [__CLASS__, 'remove_pingback_header']);
    }

    private static function enabled(): bool {
        return !empty(wp_parse_args((array) get_option(self::OPTION, []), ['enabled' => 1])['enabled']);
    }

    public static function comments_open($open, $post_id = 0): bool {
        return self::enabled() ? false : (bool) $open;
    }

    public static function hide_comments($comments, $post_id = 0): array {
        return self::enabled() ? [] : (array) $comments;
    }

    public static function disable_post_type_support(): void {
        if (!self::enabled()) return;
        foreach (get_post_types([], 'names') as $post_type) {
            remove_post_type_support($post_type, 'comments');
            remove_post_type_support($post_type, 'trackbacks');
        }
    }

    public static function empty_comment_template(string $template): string {
        if (!self::enabled()) return $template;
        $empty = __DIR__ . '/empty-comments.php';
        return is_readable($empty) ? $empty : $template;
    }

    public static function block_comment_submission(): void {
        if (self::enabled()) wp_die('此網站目前不開放留言。', '留言已停用', ['response' => 403]);
    }

    public static function remove_rest_comments($endpoints): array {
        if (!self::enabled() || !is_array($endpoints)) return $endpoints;
        foreach (array_keys($endpoints) as $route) {
            if (strpos($route, '/comments') !== false) unset($endpoints[$route]);
        }
        return $endpoints;
    }

    public static function remove_xmlrpc_methods(array $methods): array {
        if (!self::enabled()) return $methods;
        foreach (['wp.newComment', 'wp.editComment', 'wp.deleteComment', 'wp.getComment', 'wp.getComments', 'wp.getCommentCount', 'pingback.ping', 'pingback.extensions.getPingbacks'] as $method) {
            unset($methods[$method]);
        }
        return $methods;
    }

    public static function remove_pingback_header(array $headers): array {
        if (self::enabled()) unset($headers['X-Pingback']);
        return $headers;
    }

    public static function disable_comment_feed(): void {
        if (!self::enabled() || !is_comment_feed()) return;
        $url = get_permalink(get_queried_object_id());
        wp_safe_redirect($url ?: home_url('/'), 302);
        exit;
    }

    public static function remove_dashboard_widgets(): void {
        if (self::enabled()) remove_meta_box('dashboard_recent_comments', 'dashboard', 'normal');
    }

    public static function remove_comment_widgets(): void {
        if (!self::enabled()) return;
        unregister_widget('WP_Widget_Recent_Comments');
        unregister_widget('WP_Widget_Recent_Comments');
    }

    public static function remove_admin_bar_item(): void {
        if (!self::enabled() || !is_admin_bar_showing()) return;
        global $wp_admin_bar;
        if (is_object($wp_admin_bar)) $wp_admin_bar->remove_node('comments');
    }

    public static function remove_comment_menus(): void {
        if (!self::enabled()) return;
        remove_menu_page('edit-comments.php');
        remove_submenu_page('options-general.php', 'options-discussion.php');
    }

    public static function lock_admin_comments(): void {
        if (!self::enabled() || !is_admin() || wp_doing_ajax()) return;
        global $pagenow;
        if (in_array($pagenow, ['edit-comments.php', 'comment.php'], true)) {
            wp_safe_redirect(admin_url('admin.php?page=wu-disable-comments'));
            exit;
        }
    }

    public static function admin_menu(): void {
        add_submenu_page('wu-toolbox-modular', '留言停用', '留言停用', 'manage_options', 'wu-disable-comments', [__CLASS__, 'render_page']);
    }

    public static function save_settings(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足', 403);
        check_admin_referer('wutm_disable_comments_save');
        update_option(self::OPTION, ['enabled' => !empty($_POST['enabled']) ? 1 : 0], false);
        self::purge_page_caches();
        wp_safe_redirect(add_query_arg(['page' => 'wu-disable-comments', 'updated' => 1], admin_url('admin.php')));
        exit;
    }

    public static function delete_all_comments(): void {
        if (!current_user_can('manage_options')) wp_die('權限不足', 403);
        check_admin_referer('wutm_disable_comments_delete_all');
        $comments = get_comments(['status' => 'all', 'number' => 0, 'fields' => 'ids']);
        $deleted = 0;
        foreach ($comments as $comment_id) {
            if (wp_delete_comment((int) $comment_id, true)) $deleted++;
        }
        self::purge_page_caches();
        wp_safe_redirect(add_query_arg(['page' => 'wu-disable-comments', 'deleted' => $deleted], admin_url('admin.php')));
        exit;
    }

    private static function purge_page_caches(): void {
        do_action('wutm_comments_changed');
        if (function_exists('rocket_clean_domain')) rocket_clean_domain();
        if (function_exists('wp_cache_clear_cache')) wp_cache_clear_cache();
        do_action('litespeed_purge_all');
        do_action('breeze_clear_all_cache');
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $enabled = self::enabled();
        $comment_count = (int) wp_count_comments()->total_comments;
        ?>
        <div class="wrap wutm-module-wrap">
            <header class="wutm-header"><div><h1>🚫 留言停用</h1><p>一次停用全站的留言、Pingback、留言 API 與相關後台入口。</p></div><span><?php echo $enabled ? '運作中' : '未啟用'; ?></span></header>
            <?php if (!empty($_GET['updated'])): ?><div class="notice notice-success"><p>留言設定已儲存，網站快取已清除。</p></div><?php endif; ?>
            <?php if (isset($_GET['deleted'])): ?><div class="notice notice-success"><p>已永久刪除 <?php echo (int) $_GET['deleted']; ?> 則留言。</p></div><?php endif; ?>
            <section class="wu-info-panel">
                <h2>全站留言控制</h2>
                <p><strong><?php echo $enabled ? '留言功能已完全停用' : '留言功能目前開放'; ?></strong></p>
                <p class="description">啟用後會關閉文章、頁面、商品與自訂內容類型的留言和 Trackback，移除後台「留言」選單、工具列提示、儀表板區塊、留言小工具、留言 Feed、REST API 與 XML-RPC 留言入口。主題的留言模板也不會輸出。</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="wutm_disable_comments_save">
                    <?php wp_nonce_field('wutm_disable_comments_save'); ?>
                    <label class="wutm-inline-choice"><input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>> 停用整個網站的留言功能</label>
                    <?php submit_button('儲存設定', 'primary', 'submit', false); ?>
                </form>
            </section>
            <section class="wu-info-panel">
                <h2>清除既有留言</h2>
                <p>資料庫目前有 <?php echo number_format_i18n($comment_count); ?> 則留言。清除後將一併移除相關留言 Meta，無法復原。</p>
                <p class="description">建議先執行資料庫備份。此操作不會刪除文章、商品、使用者或訂單資料。</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('確定永久刪除所有留言嗎？此操作無法復原。');">
                    <input type="hidden" name="action" value="wutm_disable_comments_delete_all">
                    <?php wp_nonce_field('wutm_disable_comments_delete_all'); ?>
                    <?php submit_button('永久刪除所有留言', 'secondary', 'submit', false); ?>
                </form>
            </section>
        </div>
        <?php
    }
}
WUTM_Disable_Comments::boot();
