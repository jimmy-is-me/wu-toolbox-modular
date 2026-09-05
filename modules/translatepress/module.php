<?php
/** TranslatePress official plugin installation shortcut. */
defined('ABSPATH') || exit;
require_once WUTM_PATH . 'core/wordpress-org-plugin-installer.php';
WUTM_WordPress_Org_Plugin_Installer::register([
    'key' => 'translatepress',
    'name' => 'TranslatePress',
    'plugin_slug' => 'translatepress-multilingual',
    'plugin_folder' => 'translatepress-multilingual',
    'plugin_file' => 'translatepress-multilingual/index.php',
    'plugin_url' => 'https://wordpress.org/plugins/translatepress-multilingual/',
]);
