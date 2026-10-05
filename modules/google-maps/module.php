<?php
defined('ABSPATH') || exit;
// Disabled modules are never loaded. The map's CSS is emitted only with a rendered map.
require_once __DIR__ . '/map-manager.php';
add_action('admin_notices', static function () {
    if (!current_user_can('manage_options') || !function_exists('wumetax_map_render')) return;
    echo '<div class="notice notice-warning"><p>Google 地圖已納入 WU Toolbox；請停用舊的 WUMETAX Map Manager 程式碼片段，避免重複註冊。</p></div>';
});
