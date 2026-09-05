<?php
/** WooCommerce official plugin installation shortcut. */
defined('ABSPATH') || exit;
require_once WUTM_PATH . 'core/wordpress-org-plugin-installer.php';
WUTM_WordPress_Org_Plugin_Installer::register([
    'key' => 'woocommerce',
    'name' => 'WooCommerce',
    'plugin_slug' => 'woocommerce',
    'plugin_folder' => 'woocommerce',
    'plugin_file' => 'woocommerce/woocommerce.php',
    'plugin_url' => 'https://wordpress.org/plugins/woocommerce/',
]);
