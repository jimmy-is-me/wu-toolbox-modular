<?php
defined('ABSPATH') || exit;

add_action('admin_menu', function (): void {
    add_submenu_page('wu-toolbox-modular', 'AI SEO 工具', 'AI SEO 工具', 'manage_options', 'wu-ai-seo-tools', function (): void {
        sac_render_tools_group('seo');
    });
});
