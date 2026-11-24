<?php
/**
 * WU Toolbox Modular module: Advanced Tracking Manager.
 * Imported from jimmy-is-me/advanced-tracking-manager.
 */
defined('ABSPATH') || exit;

define('WUTM_ATM_VERSION', '3.3.1');
define('WUTM_ATM_FILE', __FILE__);
define('WUTM_ATM_DIR', plugin_dir_path(__FILE__));

require_once WUTM_ATM_DIR . 'includes/class-advanced-tracking-manager.php';
WUTM_Advanced_Tracking_Manager::get_instance();

require_once WUTM_ATM_DIR . 'includes/class-events.php';
require_once WUTM_ATM_DIR . 'includes/class-browser-evidence.php';
require_once WUTM_ATM_DIR . 'includes/class-consent-store.php';
require_once WUTM_ATM_DIR . 'includes/class-consent.php';
if (is_admin()) {
    require_once WUTM_ATM_DIR . 'includes/class-event-queue.php';
    require_once WUTM_ATM_DIR . 'includes/class-observability.php';
    require_once WUTM_ATM_DIR . 'includes/class-event-admin.php';
    require_once WUTM_ATM_DIR . 'includes/class-consent-admin.php';
    WUTM_ATM_Event_Admin::boot();
    WUTM_ATM_Consent_Admin::boot();
}
WUTM_ATM_Consent::boot();
WUTM_ATM_Events::boot();
WUTM_ATM_Browser_Evidence::boot();
