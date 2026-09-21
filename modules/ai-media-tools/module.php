<?php
defined('ABSPATH') || exit;

add_action('admin_menu', function (): void {
    add_submenu_page('wu-toolbox-modular', 'AI 圖片工具', 'AI 圖片工具', 'manage_options', 'wu-ai-media-tools', function (): void {
        sac_render_tools_group('media');
    });
});
