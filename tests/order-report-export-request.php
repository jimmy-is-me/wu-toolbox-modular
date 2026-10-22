<?php
/** Disposable native WP subprocess: let the original JSON/CSV exit normally. */
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable CI only');
if(!defined('WP_ADMIN'))define('WP_ADMIN',true);
if(!defined('WUTM_PATH'))define('WUTM_PATH',dirname(__DIR__).'/');
if(!defined('WUTM_URL'))define('WUTM_URL','https://fixture.test/plugin/');
if(!defined('WUTM_VERSION'))define('WUTM_VERSION','integration');
require_once WUTM_PATH.'core/admin-design.php';
// WooCommerce may dispatch its async queue at shutdown. Keep it offline without
// throwing after the original JSON/CSV response has already finished.
add_filter('pre_http_request',static function(){return new WP_Error('report_fixture_offline','Network disabled in isolated report fixture');},1);
add_filter('pre_wp_mail',static function(){return true;});
require_once WUTM_PATH.'modules/order-report-export/module.php';
if(!class_exists('WUTM_Order_Report_Export_V3',false))throw new RuntimeException('Report runtime not loaded');
$request=json_decode(base64_decode(getenv('WUTM_REPORT_REQUEST')),true);
wp_set_current_user((int)($request['user']??get_current_user_id()));
$_POST=$request['post']??[];$_GET=[];
$action=$request['action'];
if($action==='scan'||$action==='preview'){
    if(!defined('DOING_AJAX'))define('DOING_AJAX',true);
    $_POST['nonce']=!empty($request['bad_nonce'])?'invalid':wp_create_nonce('wctre_v3_'.$action);$_REQUEST=$_POST;
    if($action==='scan')WUTM_Order_Report_Export_V3::ajax_scan();else WUTM_Order_Report_Export_V3::ajax_preview();
}elseif($action==='submit'){
    $_POST['wctre_v3_nonce']=!empty($request['bad_nonce'])?'invalid':wp_create_nonce('wctre_v3_action');$_REQUEST=$_POST;
    WUTM_Order_Report_Export_V3::submit();
}else throw new RuntimeException('Unknown fixture action');
throw new RuntimeException('Original request handler returned unexpectedly');
