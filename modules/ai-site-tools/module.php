<?php
defined('ABSPATH') || exit;

add_action('admin_menu', function (): void {
    add_submenu_page('wu-toolbox-modular', 'AI 網站工具', 'AI 網站工具', 'manage_options', 'wu-ai-site-tools', function (): void {
        sac_render_tools_group('site');
    });
});
