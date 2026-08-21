<?php
/**
 * WU Toolbox Modular module: Advanced Tracking Manager.
 * Imported from jimmy-is-me/advanced-tracking-manager.
 */
defined('ABSPATH') || exit;

define('WUTM_ATM_VERSION', '2.4.0');
define('WUTM_ATM_FILE', __FILE__);
define('WUTM_ATM_DIR', plugin_dir_path(__FILE__));

require_once WUTM_ATM_DIR . 'includes/class-advanced-tracking-manager.php';
WUTM_Advanced_Tracking_Manager::get_instance();
