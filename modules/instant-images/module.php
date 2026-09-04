<?php
/**
 * Module: instant-images
 * 提供 Instant Images 官方外掛的一鍵安裝與啟用入口，不重製其功能。
 */
defined('ABSPATH') || exit;

final class WUTM_Instant_Images_Installer {
    private const SLUG = 'wu-instant-images';
    private const PLUGIN_SLUG = 'instant-images';
    private const PLUGIN_FILE = 'instant-images/instant-images.php';
    private const NOTICE_KEY = 'wutm_instant_images_notice';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_wutm_instant_images_install', [__CLASS__, 'install']);
        add_action('admin_post_wutm_instant_images_activate', [__CLASS__, 'activate']);
    }

    public static function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            'Instant Images',
            'Instant Images',
            'install_plugins',
            self::SLUG,
            [__CLASS__, 'page']
        );
    }

    private static function plugin_file(): string {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (file_exists(WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE)) {
            return self::PLUGIN_FILE;
        }

        foreach (get_plugins('instant-images') as $file => $data) {
            return 'instant-images/' . $file;
        }
        return self::PLUGIN_FILE;
    }

    private static function redirect(array $args = []): void {
        wp_safe_redirect(add_query_arg(array_merge(['page' => self::SLUG], $args), admin_url('admin.php')));
        exit;
    }

    private static function set_notice(string $type, string $message): void {
        set_transient(self::NOTICE_KEY, ['type' => $type, 'message' => $message], MINUTE_IN_SECONDS);
    }

    public static function install(): void {
        if (!current_user_can('install_plugins')) {
            wp_die('您沒有安裝外掛的權限。', 403);
        }
        check_admin_referer('wutm_instant_images_install');

        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $api = plugins_api('plugin_information', [
            'slug' => self::PLUGIN_SLUG,
            'fields' => ['sections' => false, 'banners' => false],
        ]);
        if (is_wp_error($api) || empty($api->download_link)) {
            self::set_notice('error', '無法取得 Instant Images 的官方安裝檔，請稍後再試。');
            self::redirect();
        }

        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $installed = $upgrader->install($api->download_link);
        if (is_wp_error($installed) || !$installed) {
            self::set_notice('error', '安裝未完成。請確認伺服器可寫入 wp-content/plugins，或改由「外掛 → 安裝外掛」手動安裝。');
            self::redirect();
        }

        $plugin = self::plugin_file();
        $activated = activate_plugin($plugin);
        if (is_wp_error($activated)) {
            self::set_notice('warning', 'Instant Images 已安裝，但尚未啟用。請在本頁點選「啟用外掛」。');
            self::redirect();
        }

        self::set_notice('success', 'Instant Images 已完成安裝並啟用。');
        self::redirect();
    }

    public static function activate(): void {
        if (!current_user_can('activate_plugins')) {
            wp_die('您沒有啟用外掛的權限。', 403);
        }
        check_admin_referer('wutm_instant_images_activate');

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $result = activate_plugin(self::plugin_file());
        self::set_notice(is_wp_error($result) ? 'error' : 'success', is_wp_error($result) ? '無法啟用 Instant Images。' : 'Instant Images 已啟用。');
        self::redirect();
    }

    public static function page(): void {
        if (!current_user_can('install_plugins')) {
            wp_die('您沒有安裝外掛的權限。', 403);
        }
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $plugin = self::plugin_file();
        $installed = file_exists(WP_PLUGIN_DIR . '/' . $plugin);
        $active = $installed && is_plugin_active($plugin);
        $notice = get_transient(self::NOTICE_KEY);
        delete_transient(self::NOTICE_KEY);
        ?>
        <div class="wrap wutm-module-wrap">
            <h1>Instant Images</h1>
            <p class="wutm-module-subtitle">這是 Instant Images 官方外掛的快速安裝入口。WU Toolbox 不會重製、修改或載入 Instant Images 的圖片搜尋功能。</p>

            <?php if (is_array($notice)) : ?>
                <div class="notice notice-<?php echo esc_attr(in_array($notice['type'], ['success', 'warning', 'error'], true) ? $notice['type'] : 'info'); ?> is-dismissible"><p><?php echo esc_html($notice['message']); ?></p></div>
            <?php endif; ?>

            <div class="card" style="max-width:780px;padding:24px;">
                <h2 style="margin-top:0;">官方外掛狀態</h2>
                <?php if ($active) : ?>
                    <p><strong style="color:#008a20;">已安裝並啟用</strong> — 可前往 <a href="<?php echo esc_url(admin_url('upload.php?page=instant-images')); ?>">媒體庫的 Instant Images</a> 開始使用。</p>
                <?php elseif ($installed) : ?>
                    <p><strong style="color:#b26200;">已安裝，尚未啟用</strong></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wutm_instant_images_activate">
                        <?php wp_nonce_field('wutm_instant_images_activate'); ?>
                        <?php submit_button('啟用 Instant Images', 'primary', 'submit', false); ?>
                    </form>
                <?php else : ?>
                    <p>按下按鈕後，系統會從 <code>wordpress.org</code> 下載、安裝並啟用官方 Instant Images 外掛。</p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="wutm_instant_images_install">
                        <?php wp_nonce_field('wutm_instant_images_install'); ?>
                        <?php submit_button('安裝並啟用 Instant Images', 'primary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>

            <p><a href="https://wordpress.org/plugins/instant-images/" target="_blank" rel="noopener noreferrer">查看 Instant Images 官方外掛頁面</a></p>
        </div>
        <?php
    }
}

WUTM_Instant_Images_Installer::boot();
