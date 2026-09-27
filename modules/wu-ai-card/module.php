<?php
/**
 * WU AI Card compatibility bridge.
 * Keep the standalone plugin as the single owner of its data and front-end UI;
 * this bridge places its settings inside WU Toolbox and applies the requested
 * floating-entry position without loading a second copy of its code.
 */
defined('ABSPATH') || exit;

if (!defined('WU_AIC_VERSION') || !function_exists('wu_aic_settings_page')) {
    return;
}

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
    if (!wp_style_is('wu-aic-style', 'enqueued')) {
        return;
    }

    $css = '.wu-aic-floating-btn,.wu-aic-floating-popup{left:24px!important;right:auto!important}'
        . '@media(max-width:480px){.wu-aic-floating-btn,.wu-aic-floating-popup{left:16px!important;right:auto!important}}';
    wp_add_inline_style('wu-aic-style', $css);
}, 21);
