<?php
/** WP Downgrade official plugin installation shortcut. */
defined('ABSPATH') || exit;
require_once WUTM_PATH . 'core/wordpress-org-plugin-installer.php';
WUTM_WordPress_Org_Plugin_Installer::register([
    'key' => 'wp-downgrade',
    'name' => 'WP Downgrade',
    'plugin_slug' => 'wp-downgrade',
    'plugin_folder' => 'wp-downgrade',
    'plugin_file' => 'wp-downgrade/wp-downgrade.php',
    'plugin_url' => 'https://wordpress.org/plugins/wp-downgrade/',
]);
