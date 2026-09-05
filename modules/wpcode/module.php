<?php
/** WPCode official plugin installation shortcut. */
defined('ABSPATH') || exit;
require_once WUTM_PATH . 'core/wordpress-org-plugin-installer.php';
WUTM_WordPress_Org_Plugin_Installer::register([
    'key' => 'wpcode',
    'name' => 'WPCode',
    'plugin_slug' => 'insert-headers-and-footers',
    'plugin_folder' => 'insert-headers-and-footers',
    'plugin_file' => 'insert-headers-and-footers/ihaf.php',
    'plugin_url' => 'https://wordpress.org/plugins/insert-headers-and-footers/',
]);
