<?php
/**
 * Module: disable-emojis
 *
 * Removes WordPress core Emoji scripts, styles and TinyMCE support when enabled.
 */
defined('ABSPATH') || exit;

final class WUTM_Disable_Emojis {
    private const SLUG = 'wu-disable-emojis';
    private const OPTION = 'wutm_disable_emojis_settings';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_wutm_disable_emojis_save', [__CLASS__, 'save']);
        if (self::is_enabled()) {
            add_action('init', [__CLASS__, 'disable'], 1);
        }
    }

    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), ['enabled' => true]);
    }

    private static function is_enabled(): bool {
        return !empty(self::settings()['enabled']);
    }

    public static function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '禁用表情符號',
            '禁用表情符號',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'page']
        );
    }

    public static function disable(): void {
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles', 'print_emoji_styles');
        remove_action('admin_print_styles', 'print_emoji_styles');
        remove_filter('the_content_feed', 'wp_staticize_emoji');
        remove_filter('comment_text_rss', 'wp_staticize_emoji');
        remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
        add_filter('tiny_mce_plugins', [__CLASS__, 'remove_tinymce_plugin']);
        add_filter('wp_resource_hints', [__CLASS__, 'remove_emoji_dns_prefetch'], 10, 2);
    }

    public static function remove_tinymce_plugin($plugins) {
        if (!is_array($plugins)) {
            return [];
        }
        return array_values(array_diff($plugins, ['wpemoji']));
    }

    public static function remove_emoji_dns_prefetch(array $urls, string $relation_type): array {
        if ($relation_type !== 'dns-prefetch') {
            return $urls;
        }
        return array_values(array_filter($urls, static function ($url): bool {
            $href = is_array($url) ? ($url['href'] ?? '') : $url;
            return strpos((string) $href, 's.w.org/images/core/emoji/') === false;
        }));
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足。', 403);
        }
        check_admin_referer('wutm_disable_emojis_save');
        update_option(self::OPTION, ['enabled' => !empty($_POST['enabled'])], false);
        wp_safe_redirect(add_query_arg(['page' => self::SLUG, 'updated' => 1], admin_url('admin.php')));
        exit;
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足。', 403);
        }
        $enabled = self::is_enabled();
        ?>
        <div class="wrap wutm-module-wrap">
            <h1>禁用表情符號</h1>
            <p class="wutm-module-subtitle">移除 WordPress 核心 Emoji 偵測碼、樣式、TinyMCE 外掛與 Emoji DNS 預先解析。</p>
            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="card" style="max-width:760px;padding:24px;">
                <input type="hidden" name="action" value="wutm_disable_emojis_save">
                <?php wp_nonce_field('wutm_disable_emojis_save'); ?>
                <h2 style="margin-top:0;">功能設定</h2>
                <label><input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?>> 啟用禁用表情符號</label>
                <p class="description">啟用後不會移除內容中的 Emoji 文字；只會停止載入 WordPress 額外提供的 Emoji 前後台資源。</p>
                <?php submit_button('儲存設定'); ?>
            </form>
        </div>
        <?php
    }
}

WUTM_Disable_Emojis::boot();
