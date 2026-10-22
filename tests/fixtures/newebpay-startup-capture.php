<?php
/** MU fixture: record startup notices before ordinary plugins load, CI only. */
if(!defined('ABSPATH')||!defined('WP_CLI')||!WP_CLI||!defined('DB_NAME')||DB_NAME!=='wci_fixture'||getenv('WUTM_MEDIA_INTEGRATION')!=='1')return;
$GLOBALS['wutm_startup_notices']=[];
add_action('doing_it_wrong_run',static function($function,$message){
    if(strpos($message,'wu-np-shipping')!==false)$GLOBALS['wutm_startup_notices'][]=$function;
},10,2);
add_filter('pre_wp_mail',static function(){return true;});
add_filter('pre_http_request',static function(){return new WP_Error('fixture-offline','No external fixture transports');},1);
// Disposable-process reproduction of the v3.8.0 contradictory declaration.
if(getenv('WUTM_STARTUP_REPRODUCE')==='1')add_action('before_woocommerce_init',static function(){
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables',WUTM_FILE,false);
},11);
