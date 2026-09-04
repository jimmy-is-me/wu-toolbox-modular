<?php
/**
 * Module: fluent-smtp
 * FluentSMTP 官方外掛的一鍵安裝入口；不重製其功能。
 */
defined('ABSPATH') || exit;

require_once WUTM_PATH . 'core/wordpress-org-plugin-installer.php';

WUTM_WordPress_Org_Plugin_Installer::register([
    'key' => 'fluent-smtp',
    'name' => 'FluentSMTP',
    'plugin_slug' => 'fluent-smtp',
    'plugin_folder' => 'fluent-smtp',
    'plugin_file' => 'fluent-smtp/fluent-smtp.php',
    'plugin_url' => 'https://wordpress.org/plugins/fluent-smtp/',
]);
