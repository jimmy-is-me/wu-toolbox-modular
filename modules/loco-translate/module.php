<?php
/** Loco Translate official plugin installation shortcut. */
defined('ABSPATH') || exit;
require_once WUTM_PATH . 'core/wordpress-org-plugin-installer.php';
WUTM_WordPress_Org_Plugin_Installer::register([
    'key' => 'loco-translate',
    'name' => 'Loco Translate',
    'plugin_slug' => 'loco-translate',
    'plugin_folder' => 'loco-translate',
    'plugin_file' => 'loco-translate/loco.php',
    'plugin_url' => 'https://wordpress.org/plugins/loco-translate/',
]);
