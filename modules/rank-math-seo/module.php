<?php
/**
 * Module: rank-math-seo
 * Rank Math SEO 官方外掛的一鍵安裝入口；不重製其功能。
 */
defined('ABSPATH') || exit;

require_once WUTM_PATH . 'core/wordpress-org-plugin-installer.php';

WUTM_WordPress_Org_Plugin_Installer::register([
    'key' => 'rank-math-seo',
    'name' => 'Rank Math SEO',
    'plugin_slug' => 'seo-by-rank-math',
    'plugin_folder' => 'seo-by-rank-math',
    'plugin_file' => 'seo-by-rank-math/rank-math.php',
    'plugin_url' => 'https://wordpress.org/plugins/seo-by-rank-math/',
]);
