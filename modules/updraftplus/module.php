<?php
/**
 * Module: updraftplus
 * UpdraftPlus 官方外掛的一鍵安裝入口；不重製其功能。
 */
defined('ABSPATH') || exit;

require_once WUTM_PATH . 'core/wordpress-org-plugin-installer.php';

WUTM_WordPress_Org_Plugin_Installer::register([
    'key' => 'updraftplus',
    'name' => 'UpdraftPlus',
    'plugin_slug' => 'updraftplus',
    'plugin_folder' => 'updraftplus',
    'plugin_file' => 'updraftplus/updraftplus.php',
    'plugin_url' => 'https://wordpress.org/plugins/updraftplus/',
]);
