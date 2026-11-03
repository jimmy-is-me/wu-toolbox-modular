<?php
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable fixture only');
define('WUTM_VERSION','fixture');
require dirname(__DIR__).'/core/license-manager.php';
require dirname(__DIR__).'/core/module-registry.php';
require dirname(__DIR__).'/core/module-access.php';
$client=WUTM_License_Manager::instance();$saved=[];
foreach([WUTM_License_Manager::UUID_OPTION,WUTM_License_Manager::IDENTITY_OPTION,WUTM_License_Manager::IDENTITY_HASH_OPTION,WUTM_License_Manager::STATE_OPTION,WUTM_License_Manager::KEY_OPTION,WUTM_License_Manager::TOKEN_OPTION] as $key){$saved[$key]=get_option($key,null);delete_option($key);}
$url='https://temporary.example.test/';$calls=[];$response=['activation_token'=>'fixture-token','valid'=>true,'status'=>'active'];
$home=static function()use(&$url){return $url;};add_filter('home_url',$home,999);
$transport=static function($pre,$args,$endpoint)use(&$calls,&$response){$calls[]=[$endpoint,$args];return ['headers'=>[],'body'=>wp_json_encode($response),'response'=>['code'=>200,'message'=>'fixture']];};add_filter('pre_http_request',$transport,999,3);
try {
    if(!$client->activate('FIXTURE-KEY')['success'])throw new RuntimeException('Initial activation');
    $old=$client->site_uuid();$url='https://official.example.test/';
    update_option(WUTM_License_Manager::IDENTITY_OPTION,'official.example.test');
    $state=$client->state();$state['site_url']=$url;$state['site_identity']='official.example.test';update_option(WUTM_License_Manager::STATE_OPTION,$state);
    if($client->is_valid())throw new RuntimeException('URL-replaced old validation must not stay valid');
    if($client->site_uuid()===$old||$client->token())throw new RuntimeException('New domain identity and invalidation');
    ob_start();$client->render_panel();$html=ob_get_clean();
    if(strpos($html,'重建目前網域識別')===false||strpos($html,'confirm_domain_move')===false||strpos($html,'name="_wpnonce"')===false)throw new RuntimeException('Real recovery form with confirmation and nonce');
    $client->reset_site_identity();$response=['code'=>'site_limit','message'=>'Fixture quota'];
    if($client->activate('FIXTURE-KEY')['success']||$client->is_valid())throw new RuntimeException('Quota must not be bypassed');
    $response=['activation_token'=>'new-token','valid'=>true,'status'=>'active'];
    if(!$client->activate('FIXTURE-KEY')['success']||!$client->is_valid())throw new RuntimeException('Normal server-approved rebind');
    foreach($calls as [$endpoint,$args])if(str_ends_with($endpoint,'/deactivate'))throw new RuntimeException('Unexpected old site release');
    echo "Native license domain migration passed: actual options, URL replacement, UUID rotation, recovery form, quota and authorized rebind.\n";
} finally {
    remove_filter('home_url',$home,999);remove_filter('pre_http_request',$transport,999);
    foreach($saved as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}
    wp_clear_scheduled_hook(WUTM_License_Manager::CRON_HOOK);
}
