<?php
/** GTranslate official plugin installation shortcut. */
defined('ABSPATH') || exit;
require_once WUTM_PATH . 'core/wordpress-org-plugin-installer.php';
WUTM_WordPress_Org_Plugin_Installer::register([
    'key' => 'gtranslate',
    'name' => 'GTranslate',
    'plugin_slug' => 'gtranslate',
    'plugin_folder' => 'gtranslate',
    'plugin_file' => 'gtranslate/gtranslate.php',
    'plugin_url' => 'https://wordpress.org/plugins/gtranslate/',
]);
