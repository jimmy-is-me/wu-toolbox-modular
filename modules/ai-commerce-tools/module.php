<?php
defined('ABSPATH') || exit;

add_action('admin_menu', function (): void {
    add_submenu_page('wu-toolbox-modular', 'AI 電商工具', 'AI 電商工具', 'manage_options', 'wu-ai-commerce-tools', function (): void {
        sac_render_tools_group('commerce');
    });
});
