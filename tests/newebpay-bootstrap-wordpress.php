<?php
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable CI only');
function np_boot_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
np_boot_assert(defined('WUTM_NEWEBPAY_TOOLS')&&class_exists('WU_NP_NewebPay_Gateway',false),'Real active plugin loaded the combined runtime');
np_boot_assert(class_exists('WUTM_PAYUNi_Payment_Module',false)&&class_exists('WUTM_LINEPay_Payment_Module',false),'Other compatibility declarations loaded');
np_boot_assert(class_exists('WuDigital\\Payuni\\Shipping\\PayuniShipping',false),'PAYUNi shipping starts alongside payment without a duplicate settings-tab/compatibility fatal');
np_boot_assert(did_action('before_woocommerce_init')>0,'Real WooCommerce initialization completed without conflicting declarations');
np_boot_assert(array_key_exists('wutm_startup_notices',$GLOBALS),'Early MU capture must be installed');
np_boot_assert(!$GLOBALS['wutm_startup_notices'],'No early shipping script notice during cold startup');
do_action('wp_enqueue_scripts');
np_boot_assert(wp_script_is('wu-np-shipping','registered'),'Real frontend registration works on enqueue hook');
np_boot_assert(!$GLOBALS['wutm_startup_notices'],'No shipping registration notice after hook');
echo "Real cold WordPress/WooCommerce plugin startup passed: PAYUNi + LINE Pay + NewebPay/EZPAY coexist; no declaration fatal or early shipping notice.\n";
