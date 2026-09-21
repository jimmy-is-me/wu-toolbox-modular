<?php
defined('ABSPATH') || exit;

add_action('admin_menu', function (): void {
    add_submenu_page('wu-toolbox-modular', 'AI 操作紀錄', 'AI 操作紀錄', 'manage_options', 'wu-ai-operation-log', function (): void {
        sac_render_tools_group('logs');
    });
});
