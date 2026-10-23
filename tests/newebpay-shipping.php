<?php
/** Rate retention contracts, including WooCommerce 9.9+ core hiding policy. */
define('ABSPATH', __DIR__);
$options = ['woocommerce_shipping_hide_rates_when_free'=>'yes','WU_NP_WT_enabled_newebpay_shipping'=>'yes'];
function add_action(...$args) {} function add_filter(...$args) {}
function get_option($key,$default=false) {return $GLOBALS['options'][$key]??$default;}
function wp_json_encode($value) {return json_encode($value);}
function set_transient(...$args) {} function wp_generate_uuid4(){return 'fixture-revision';}
class WU_NP_WT {static function get_option($key,$default=false){return get_option('WU_NP_WT_'.$key,$default);}}
class WC_Cache_Helper {static $calls=0;static function get_transient_version(...$args){++self::$calls;}}
class TestRate {public $cost=60;private $id;function __construct($id){$this->id=$id;}function get_method_id(){return $this->id;}}
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);}
require dirname(__DIR__).'/modules/newebpay-tools/shipping-runtime.php';
$package=['destination'=>['country'=>'TW'],'contents'=>['a'=>['quantity'=>1,'data'=>new stdClass()]],'rates'=>[]];
$cvs=new TestRate('wu_np_newebpay_shipping_cvs');$free=new TestRate('free_shipping');
$method=(object)['id'=>'wu_np_newebpay_shipping_cvs','rates'=>['cvs:3'=>$cvs]];
WUTM_Newebpay_Shipping_Rates::capture($package,$method);
$rates=WUTM_Newebpay_Shipping_Rates::retain(['free:1'=>$free],$package);
verify(isset($rates['cvs:3'])&&$rates['cvs:3']->cost===60,'Keep actual pickup rate without changing price');
verify(count(WUTM_Newebpay_Shipping_Rates::retain(['free:1'=>$free],$package))===1,'No stale rate reuse');
foreach(['disabled','optout','no-free','other-package','unavailable','foreign'] as $mode){
    $options['WU_NP_WT_enabled_newebpay_shipping']=$mode==='disabled'?'no':'yes';
    $options['WU_NP_WT_newebpay_shipping_keep_with_free']=$mode==='optout'?'no':'yes';
    $method->id=$mode==='foreign'?'other':'wu_np_newebpay_shipping_cvs';
    $method->rates=$mode==='unavailable'?[]:['cvs:3'=>$cvs];
    WUTM_Newebpay_Shipping_Rates::capture($package,$method);
    $input=$mode==='no-free'?[]:['free:1'=>$free];$target=$package;
    if($mode==='other-package')$target['destination']['country']='JP';
    verify(WUTM_Newebpay_Shipping_Rates::retain($input,$target)===$input,'Respect boundary: '.$mode);
    WUTM_Newebpay_Shipping_Rates::retain([],$package);
}
WUTM_Newebpay_Shipping_Rates::invalidate('unrelated');verify(WC_Cache_Helper::$calls===0,'Unrelated settings unchanged');
WUTM_Newebpay_Shipping_Rates::invalidate('WU_NP_WT_enabled_newebpay_shipping');verify(WC_Cache_Helper::$calls===2,'Shipping cache refreshed on service changes');
echo "NewebPay shipping retention, package isolation, opt-out, unavailable methods and cache invalidation passed.\n";
