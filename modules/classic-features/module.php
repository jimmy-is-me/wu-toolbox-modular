<?php
/**
 * Module: classic-features
 *
 * Restores the classic post editor and classic widget management screens,
 * and optionally expands the WordPress TinyMCE toolbar.
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

        if (self::is_enabled('advanced_editor_tools')) {
            self::enable_advanced_editor_tools();
        }

        if (self::is_enabled('disable_big_image_threshold')) {
            self::disable_big_image_threshold();
        }
    }

    private static function settings(): array {
        return wp_parse_args((array) get_option(self::OPTION, []), [
            'classic_editor' => false,
            'classic_widgets' => false,
            'advanced_editor_tools' => false,
            'disable_big_image_threshold' => false,
        ]);
    }

    private static function is_enabled(string $key): bool {
        $settings = self::settings();
        return !empty($settings[$key]);
    }

    private static function enable_classic_editor(): void {
        add_filter('use_block_editor_for_post_type', '__return_false', 100);
        add_filter('use_block_editor_for_post', '__return_false', 100);
        add_filter('gutenberg_can_edit_post_type', '__return_false', 100);
        add_filter('gutenberg_can_edit_post', '__return_false', 100);

        add_action('admin_init', [__CLASS__, 'remove_editor_promotion']);
        add_action('admin_head-post.php', [__CLASS__, 'classic_editor_compatibility_css']);
        add_action('admin_head-post-new.php', [__CLASS__, 'classic_editor_compatibility_css']);
    }

    private static function enable_classic_widgets(): void {
        add_filter('use_widgets_block_editor', '__return_false', 100);
        add_filter('gutenberg_use_widgets_block_editor', '__return_false', 100);
    }

    private static function disable_big_image_threshold(): void {
        add_filter('big_image_size_threshold', '__return_false', 100);
    }

    private static function enable_advanced_editor_tools(): void {
        add_filter('mce_buttons', [__CLASS__, 'advanced_editor_first_row'], 999, 2);
        add_filter('mce_buttons_2', [__CLASS__, 'advanced_editor_second_row'], 999, 2);
        add_filter('mce_external_plugins', [__CLASS__, 'advanced_editor_plugins'], 999);
        add_filter('tiny_mce_before_init', [__CLASS__, 'advanced_editor_settings'], 20, 2);
    }

    public static function advanced_editor_first_row($buttons, $editor_id = ''): array {
        $buttons = is_array($buttons) ? $buttons : [];
        $extra = ['styleselect', 'underline', 'strikethrough', 'alignjustify', 'wp_adv'];

        return array_values(array_unique(array_merge($buttons, $extra)));
    }

    public static function advanced_editor_second_row($buttons, $editor_id = ''): array {
        $buttons = is_array($buttons) ? $buttons : [];
        $extra = [
            'fontselect',
            'fontsizeselect',
            'forecolor',
            'backcolor',
            'subscript',
            'superscript',
            'hr',
            'charmap',
            'pastetext',
            'removeformat',
            'outdent',
            'indent',
            'table',
            'searchreplace',
            'code',
            'anchor',
            'insertdatetime',
            'nonbreaking',
            'visualblocks',
            'visualchars',
        ];

        return array_values(array_unique(array_merge($buttons, $extra)));
    }

    public static function advanced_editor_plugins($plugins): array {
        $plugins = is_array($plugins) ? $plugins : [];
        $base_url = WUTM_URL . 'modules/classic-features/vendor/tinymce/';

        foreach ([
            'advlist',
            'anchor',
            'code',
            'insertdatetime',
            'nonbreaking',
            'searchreplace',
            'table',
            'visualblocks',
            'visualchars',
        ] as $plugin) {
            $plugins[$plugin] = $base_url . $plugin . '/plugin.min.js';
        }

        return $plugins;
    }

    public static function advanced_editor_settings($settings, $editor_id = ''): array {
        $settings = is_array($settings) ? $settings : [];
        $settings['fontsize_formats'] = '8px 10px 12px 14px 16px 18px 20px 24px 28px 32px 36px 48px 60px 72px 96px';
        $settings['block_formats'] = '段落=p;標題 1=h1;標題 2=h2;標題 3=h3;標題 4=h4;標題 5=h5;標題 6=h6;預先格式化=pre';
        $settings['wordpress_adv_hidden'] = false;

        return $settings;
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
            'advanced_editor_tools' => !empty($_POST['advanced_editor_tools']),
            'disable_big_image_threshold' => !empty($_POST['disable_big_image_threshold']),
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
        $enabled_count =
            (int) !empty($settings['classic_editor'])
            + (int) !empty($settings['classic_widgets'])
            + (int) !empty($settings['advanced_editor_tools'])
            + (int) !empty($settings['disable_big_image_threshold']);
        ?>
        <div class="wrap wutm-module-wrap wutm-classic-features">
            <h1>經典功能設定</h1>
            <p class="wutm-module-subtitle">依需求恢復並強化 WordPress 傳統編輯體驗；未勾選的功能不會介入後台畫面。</p>

            <?php if (!empty($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p>經典功能設定已儲存。</p></div>
            <?php endif; ?>

            <div class="card" style="max-width:100%;margin:20px 0;">
                <h2 style="margin-top:0;">目前狀態</h2>
                <p>
                    已啟用 <strong><?php echo (int) $enabled_count; ?> / 4</strong> 項經典功能。
                    儲存後重新開啟文章編輯器或「外觀 → 小工具」即可看到變更。
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
                        <tr>
                            <th scope="row">進階編輯器工具</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="advanced_editor_tools" value="1" <?php checked(!empty($settings['advanced_editor_tools'])); ?>>
                                    啟用進階 TinyMCE 工具列
                                </label>
                                <p class="description">
                                    增加字型、字級、文字與背景色、底線、縮排、上下標、表格、搜尋取代、原始碼、錨點及格式檢視等工具。
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">大型尺寸圖片限制</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="disable_big_image_threshold" value="1" <?php checked(!empty($settings['disable_big_image_threshold'])); ?>>
                                    停用 WordPress 的大型尺寸圖片限制
                                </label>
                                <p class="description">
                                    WordPress 5.3 以上版本預設會將超過 2560px 的圖片建立為「-scaled」檔案；啟用後會保留原始尺寸。這不會停用一般縮圖或壓縮設定。
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
                    <li>三項功能可分開開啟，不必同時啟用。</li>
                    <li>進階工具會套用到 WordPress 的 TinyMCE 編輯器；搭配經典編輯器使用時功能最完整。</li>
                    <li>所有功能只調整後台編輯介面，不會改寫既有文章、頁面或小工具資料。</li>
                    <li>大型尺寸圖片限制只影響 WordPress 自動建立的「-scaled」圖片；請確認主機磁碟空間與圖片最佳化流程能負荷原始尺寸。</li>
                    <li>若網站另外安裝相同類型的經典或 TinyMCE 工具外掛，建議只保留一套啟用來源。</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

WUTM_Classic_Features::boot();
