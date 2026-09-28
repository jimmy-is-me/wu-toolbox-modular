<?php
/**
 * WU AI Card native Toolbox module.
 *
 * The implementation is bundled with this module. If the old standalone
 * WU AI Card plugin is still active, reuse its already-loaded implementation
 * for this request to avoid declaring its functions twice during migration.
 */
defined('ABSPATH') || exit;

if (function_exists('wu_aic_settings_page')) {
    add_action('admin_menu', static function (): void {
        remove_menu_page('wu-ai-card');
        add_submenu_page(
            'wu-toolbox-modular',
            'AI 名片設定',
            'AI 名片設定',
            'manage_options',
            'wu-ai-card',
            'wu_aic_settings_page'
        );
    }, 1000);

    add_action('wp_enqueue_scripts', static function (): void {
        if (!wp_style_is('wu-aic-style', 'enqueued')) return;
        wp_add_inline_style('wu-aic-style', '.wu-aic-floating-btn,.wu-aic-floating-popup{left:24px!important;right:auto!important}@media(max-width:480px){.wu-aic-floating-btn,.wu-aic-floating-popup{left:16px!important;right:auto!important}}');
    }, 21);

    add_action('admin_notices', static function (): void {
        if (!current_user_can('activate_plugins')) return;
        echo '<div class="notice notice-info"><p>WU AI Card 已整合為 WU Toolbox 內建模組。停用外掛列表中的舊版獨立「WU AI Card」後，名片設定資料會保留並由內建模組接手。</p></div>';
    });

    return;
}

defined('WU_AIC_VERSION') || define('WU_AIC_VERSION', '1.2.1');
defined('WU_AIC_OPTION') || define('WU_AIC_OPTION', 'wu_aic_data');
defined('WU_AIC_SLUG') || define('WU_AIC_SLUG', 'wu-ai-card');

require_once __DIR__ . '/implementation.php';

/** Flush once after a newly enabled module has registered its rewrite rule. */
add_action('init', static function (): void {
    if (get_option('wutm_wu_ai_card_rewrite_version') === WU_AIC_VERSION) return;
    flush_rewrite_rules(false);
    update_option('wutm_wu_ai_card_rewrite_version', WU_AIC_VERSION, false);
}, 99);

/** Remove only this module's route before regenerating rewrite rules on disable. */
add_action('wutm_module_toggled', static function (string $key, bool $enabled): void {
    if ($key !== 'wu-ai-card' || $enabled) return;

    remove_action('init', 'wu_aic_add_rewrite_rule', 5);
    global $wp_rewrite;
    if (isset($wp_rewrite->extra_rules_top) && is_array($wp_rewrite->extra_rules_top)) {
        foreach ($wp_rewrite->extra_rules_top as $pattern => $query) {
            if (strpos((string) $query, 'wu_aic_page=1') !== false) unset($wp_rewrite->extra_rules_top[$pattern]);
        }
    }
    flush_rewrite_rules(false);
    delete_option('wutm_wu_ai_card_rewrite_version');
}, 10, 2);
