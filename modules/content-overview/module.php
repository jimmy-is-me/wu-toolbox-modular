<?php
defined('ABSPATH') || exit;
// No front-end queries, assets or large admin implementation are loaded.
if (!is_admin()) return;
require_once __DIR__ . '/content-overview.php';
add_action('admin_notices', static function () {
    if (!current_user_can('manage_options') || !class_exists('WCO_Content_Overview_V5')) return;
    echo '<div class="notice notice-warning"><p>內容總覽已納入 WU Toolbox；請停用舊的 WordPress 內容總覽程式碼片段，避免重複選單。</p></div>';
});
