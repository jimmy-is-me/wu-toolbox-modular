<?php
/** Wordfence official plugin installation shortcut. */
defined('ABSPATH') || exit;
require_once WUTM_PATH . 'core/wordpress-org-plugin-installer.php';
WUTM_WordPress_Org_Plugin_Installer::register([
    'key' => 'wordfence',
    'name' => 'Wordfence',
    'plugin_slug' => 'wordfence',
    'plugin_folder' => 'wordfence',
    'plugin_file' => 'wordfence/wordfence.php',
    'plugin_url' => 'https://wordpress.org/plugins/wordfence/',
]);
