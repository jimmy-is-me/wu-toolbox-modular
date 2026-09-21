<?php
defined('ABSPATH') || exit;

add_action('admin_menu', function (): void {
    add_submenu_page('wu-toolbox-modular', 'AI 文章與 FAQ', 'AI 文章與 FAQ', 'manage_options', 'wu-ai-content-tools', function (): void {
        sac_render_tools_group('content');
    });
});
