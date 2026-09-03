<?php
/**
 * Module: classic-features
 *
 * Restores the classic post editor and classic widget management screens.
 * The implementation follows the behavior of the official Classic Editor
 * and Classic Widgets plugins without loading their standalone settings UI.
 */
defined('ABSPATH') || exit;

final class WUTM_Classic_Features {
    private const SLUG = 'wu-classic-features';
    private const OPTION = 'wutm_classic_features_settings';

    public static function boot(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 20);
        add_action('admin_post_wutm_classic_features_save', [__CLASS__, 'save']);

        if (self::is_enabled('classic_editor')) {
            self::enable_classic_editor();
        }

        if (self::is_enabled('classic_widgets')) {
            self::enable_classic_widgets();
        }
    }

    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'classic_editor' => false,
            'classic_widgets' => false,
        ]);
    }

    private static function is_enabled(string $key): bool {
        $settings = self::settings();
        return !empty($settings[$key]);
    }

    private static function enable_classic_editor(): void {
        // WordPress core block editor.
        add_filter('use_block_editor_for_post_type', '__return_false', 100);
        add_filter('use_block_editor_for_post', '__return_false', 100);

        // Compatibility with sites that also run the Gutenberg plugin.
        add_filter('gutenberg_can_edit_post_type', '__return_false', 100);
        add_filter('gutenberg_can_edit_post', '__return_false', 100);

        // Remove the obsolete editor promotion panel on older WordPress releases.
        add_action('admin_init', [__CLASS__, 'remove_editor_promotion']);

        // Keep the classic publish box usable on newer WordPress admin layouts.
        add_action('admin_head-post.php', [__CLASS__, 'classic_editor_compatibility_css']);
        add_action('admin_head-post-new.php', [__CLASS__, 'classic_editor_compatibility_css']);
    }

    private static function enable_classic_widgets(): void {
        // WordPress core and Gutenberg plugin widget editors.
        add_filter('use_widgets_block_editor', '__return_false', 100);
        add_filter('gutenberg_use_widgets_block_editor', '__return_false', 100);
    }

    public static function remove_editor_promotion(): void {
        remove_action('try_gutenberg_panel', 'wp_try_gutenberg_panel');
    }

    public static function classic_editor_compatibility_css(): void {
        ?>
        <style id="wutm-classic-editor-compatibility">
            #major-publishing-actions { flex-wrap: wrap; }
        </style>
        <?php
    }

    public static function menu(): void {
        add_submenu_page(
            'wu-toolbox-modular',
            '經典功能設定',
            '經典功能',
            'manage_options',
            self::SLUG,
            [__CLASS__, 'page']
        );
    }

    public static function save(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }

        check_admin_referer('wutm_classic_features_save');

        update_option(self::OPTION, [
            'classic_editor' => !empty($_POST['classic_editor']),
            'classic_widgets' => !empty($_POST['classic_widgets']),
        ], false);

        wp_safe_redirect(add_query_arg([
            'page' => self::SLUG,
            'updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public static function page(): void {
        if (!current_user_can('manage_options')) {
            wp_die('權限不足', 403);
        }

        $settings = self::settings();
        $enabled_count = (int) !empty($settings['classic_editor']) + (int) !empty($settings['classic_widgets']);
        ?>
        <div class="wrap wutm-module-wrap wutm-classic-features">
            <h1>經典功能設定</h1>
            <p class="wutm-module-subtitle">依需求恢復 WordPress 傳統編輯體驗；未勾選的功能不會介入後台畫面。</p>

            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>經典功能設定已儲存。</p></div>
            <?php endif; ?>

            <div class="card" style="max-width:100%;margin:20px 0;">
                <h2 style="margin-top:0;">目前狀態</h2>
                <p>
                    已啟用 <strong><?php echo (int) $enabled_count; ?> / 2</strong> 項經典功能。
                    儲存設定後，重新開啟文章編輯器或「外觀 → 小工具」即可看到變更。
                </p>
            </div>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wutm_classic_features_save">
                <?php wp_nonce_field('wutm_classic_features_save'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">經典編輯器</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="classic_editor" value="1" <?php checked(!empty($settings['classic_editor'])); ?>>
                                    啟用經典編輯器
                                </label>
                                <p class="description">
                                    文章、頁面、商品及支援編輯器的自訂內容類型，會使用傳統 TinyMCE 編輯畫面，不載入區塊編輯器。
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">經典小工具</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="classic_widgets" value="1" <?php checked(!empty($settings['classic_widgets'])); ?>>
                                    啟用經典小工具
                                </label>
                                <p class="description">
                                    「外觀 → 小工具」與自訂器改用傳統小工具管理畫面，不載入區塊式小工具編輯器。
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button('儲存設定'); ?>
            </form>

            <div class="card" style="max-width:100%;margin-top:20px;">
                <h2 style="margin-top:0;">功能說明</h2>
                <ul style="list-style:disc;padding-left:22px;">
                    <li>兩項功能可分開開啟，不必同時啟用。</li>
                    <li>只調整 WordPress 後台的編輯介面，不會改寫既有文章、頁面或小工具資料。</li>
                    <li>若網站另外安裝經典編輯器或經典小工具外掛，建議只保留一套啟用來源，方便日後維護。</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

WUTM_Classic_Features::boot();
