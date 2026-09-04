<?php
/**
 * Module: wpvivid
 * WPvivid 官方外掛的一鍵安裝入口；不重製其功能。
 */
defined('ABSPATH') || exit;

require_once WUTM_PATH . 'core/wordpress-org-plugin-installer.php';

WUTM_WordPress_Org_Plugin_Installer::register([
    'key' => 'wpvivid',
    'name' => 'WPvivid',
    'plugin_slug' => 'wpvivid-backuprestore',
    'plugin_folder' => 'wpvivid-backuprestore',
    'plugin_file' => 'wpvivid-backuprestore/wpvivid-backuprestore.php',
    'plugin_url' => 'https://wordpress.org/plugins/wpvivid-backuprestore/',
]);
