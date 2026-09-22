<?php
/**
 * 多語言管理模組
 *
 * 提供 TranslatePress 語言數量解鎖與語言設定概覽。
 * 所有功能只在模組啟用且相依外掛存在時載入。
 */
defined('ABSPATH') || exit;

if (!class_exists('TRP_Translate_Press')) {
    return;
}

final class WUTM_TranslatePress_Addons {
    private const MAX_LANGUAGES = 1000;

    public static function boot(): void {
        add_filter('trp_secondary_languages', [__CLASS__, 'unlock_languages'], 5);
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 30);
    }

    public static function unlock_languages($limit): int {
        return max((int) $limit, self::MAX_LANGUAGES);
    }

    public static function admin_menu(): void {
        add_submenu_page('wu-toolbox-modular', '多語言管理', '多語言管理', 'manage_options', 'wu-translatepress-addons', [__CLASS__, 'render_page']);
    }

    private static function languages(): array {
        $trp = get_option('trp_settings', []);
        if (!is_array($trp)) return [];
        $languages = [];
        $default = $trp['default-language'] ?? '';
        $additional = $trp['translation-languages'] ?? [];
        if (is_string($default) && $default !== '') $languages[] = $default;
        if (is_array($additional)) foreach ($additional as $language) if (is_string($language) && $language !== '') $languages[] = $language;
        return array_values(array_unique($languages));
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;
        $languages = self::languages();
        $count = count($languages);
        $running = class_exists('TRP_Translate_Press');
        ?>
        <div class="wrap wutm-module-wrap">
            <header class="wutm-header"><div><h1>🌐 多語言管理</h1><p>解除語言數量限制，集中確認 TranslatePress 的語言設定與運作狀態。</p></div><span><?php echo $running ? '運作中' : '待設定'; ?></span></header>
            <div class="wutm-grid" style="margin-top:20px">
                <section class="wu-stat-box"><h2>已設定語言數量</h2><p style="font-size:42px;line-height:1;margin:8px 0;color:var(--wutm-wp-blue)"><?php echo esc_html((string) $count); ?></p><p class="description">已移除次要語言限制，最多可設定 <?php echo esc_html((string) self::MAX_LANGUAGES); ?> 種語言。</p></section>
                <section class="wu-stat-box"><h2>模組狀態</h2><p><strong><?php echo $running ? '運作中' : '尚未設定'; ?></strong></p><p class="description"><?php echo $running ? '語言數量解鎖已載入，可直接在 TranslatePress 管理翻譯內容。' : '請先啟用 TranslatePress。'; ?></p></section>
            </div>
            <section class="wu-info-panel" style="margin-top:20px"><h2>已設定語言</h2><?php if ($languages): ?><ul><?php foreach ($languages as $language): ?><li><?php echo esc_html($language); ?></li><?php endforeach; ?></ul><?php else: ?><p>目前尚未讀取到語言設定，請先在 TranslatePress 設定語言。</p><?php endif; ?></section>
        </div>
        <?php
    }
}
WUTM_TranslatePress_Addons::boot();
