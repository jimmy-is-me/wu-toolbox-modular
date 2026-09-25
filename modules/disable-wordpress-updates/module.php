<?php
/**
 * Module: disable-wordpress-updates
 * 停止 WordPress 核心、外掛與佈景主題的更新檢查及自動更新。
 */
defined('ABSPATH') || exit;

final class WUTM_Disable_WordPress_Updates {
    private const SLUG = 'wu-disable-wordpress-updates';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);

        add_filter('automatic_updater_disabled', '__return_true');
        add_filter('auto_update_core', '__return_false');
        add_filter('auto_update_plugin', '__return_false');
        add_filter('auto_update_theme', '__return_false');
        add_filter('allow_dev_auto_core_updates', '__return_false');
        add_filter('allow_minor_auto_core_updates', '__return_false');
        add_filter('allow_major_auto_core_updates', '__return_false');

        add_filter('pre_site_transient_update_core', [__CLASS__, 'empty_core_updates']);
        add_filter('pre_site_transient_update_plugins', [__CLASS__, 'empty_plugin_updates']);
        add_filter('pre_site_transient_update_themes', [__CLASS__, 'empty_theme_updates']);

        remove_action('init', 'wp_version_check');
        remove_action('admin_init', '_maybe_update_core');
        remove_action('load-plugins.php', 'wp_update_plugins');
        remove_action('load-update.php', 'wp_update_plugins');
        remove_action('load-update-core.php', 'wp_update_plugins');
        remove_action('load-themes.php', 'wp_update_themes');
        remove_action('load-update.php', 'wp_update_themes');
        remove_action('load-update-core.php', 'wp_update_themes');
    }

    public static function empty_core_updates($transient) {
        return (object) [
            'last_checked' => time(),
            'version_checked' => get_bloginfo('version'),
            'updates' => [],
        ];
    }

    public static function empty_plugin_updates($transient) {
        $plugin_file = plugin_basename(WUTM_FILE);
        $updates = (object) [
            'last_checked' => time(),
            'checked' => [$plugin_file => WUTM_VERSION],
            'response' => [],
            'no_update' => [],
        ];

        // Keep the Toolbox's own GitHub release update visible while suppressing all other plugin updates.
        $updater = $GLOBALS['wutm_github_release_updater'] ?? null;
        if ($updater instanceof WUTM_GitHub_Release_Updater) {
            $updates = $updater->inject_update($updates);
        }

        $updates->response = isset($updates->response[$plugin_file]) ? [$plugin_file => $updates->response[$plugin_file]] : [];
        $updates->no_update = isset($updates->no_update[$plugin_file]) ? [$plugin_file => $updates->no_update[$plugin_file]] : [];
        return $updates;
    }

    public static function empty_theme_updates($transient) {
        return (object) [
            'last_checked' => time(),
            'checked' => [],
            'response' => [],
            'no_update' => [],
        ];
    }

    public static function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '停用所有更新',
            '停用所有更新',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'page']
        );
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足。', 403);
        }
        ?>
        <div class="wrap wutm-module-wrap">
            <h1>停用所有更新</h1>
            <p class="wutm-module-subtitle">此模組啟用期間，會停止 WordPress 核心、外掛與佈景主題的更新檢查、自動更新與更新提示。</p>
            <p class="description">此開關不影響 WU Toolbox 自身的 GitHub 更新檢查與更新通知。</p>
            <div class="notice notice-warning inline"><p><strong>安全提醒：</strong>關閉更新可能讓網站錯過安全修補。需要更新時，請先回到 WU Toolbox 關閉此模組，再進入「控制台 → 更新」檢查與執行更新。</p></div>
            <div class="card" style="max-width:760px;padding:24px;">
                <h2 style="margin-top:0;">目前狀態：已停用更新</h2>
                <p>卡片開關即為功能開關。本頁不提供額外設定，避免出現「看起來已停用、實際仍會更新」的混淆狀況。</p>
            </div>
        </div>
        <?php
    }
}

WUTM_Disable_WordPress_Updates::boot();
