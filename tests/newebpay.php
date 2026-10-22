<?php
/** Offline registration/crypto checks. No merchant HTTP requests. */
if(PHP_SAPI!=='cli')exit;
error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH',__DIR__.'/'); define('WUTM_PATH',dirname(__DIR__).'/'); define('WUTM_URL','https://fixture.test/plugin/'); define('WUTM_VERSION','fixture');
$mode=$argv[1]??'enabled'; $hooks=[]; $options=[];
function add_action($name,$fn,$priority=10,...$args){$GLOBALS['hooks'][$name][$priority][]=$fn;}
function add_filter(...$args){add_action(...$args);}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;return true;}
function is_admin(){return true;}
function __($s,...$args){return $s;} function _x($s,...$args){return $s;} function _n_noop(...$a){return $a;}
function register_post_status(...$args){}
function did_action($name){return 0;}
function get_woocommerce_currency(){return $GLOBALS['currency']??'TWD';}
function wc_string_to_bool($value){return in_array($value,['yes','1',1,true],true);}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);}
function apply_filters($name,$value,...$args){$groups=$GLOBALS['hooks'][$name]??[];ksort($groups);foreach($groups as $callbacks)foreach($callbacks as $fn)$value=$fn($value,...$args);return $value;}
if($mode!=='no-woocommerce') {class WooCommerce {} class WC_Payment_Gateway {} class WC_Shipping_Method {}}
if($mode==='conflict') {class RY_NewebPay_Gateway {const FOREIGN_MARKER='foreign';}}
require WUTM_PATH.'modules/newebpay-tools/runtime.php';
foreach(json_decode(file_get_contents(__DIR__.'/newebpay-source-files.json'),true) as $file=>$entry) {
    $adapted=json_decode(file_get_contents(__DIR__.'/newebpay-adapted-files.json'),true);
    $data=file_get_contents(WUTM_PATH.'modules/newebpay-tools/vendor/'.$file);
    $hash=hash('sha256',str_replace("\r\n","\n",$data));
    check($hash===($adapted[$file]??$entry['sha256']),'Source checksum mismatch: '.$file);
}
$before=$options;wutm_newebpay_boot();check($before===$options,'Boot must never enable services or change credentials');
if($mode==='no-woocommerce'||$mode==='conflict') {
    check(!class_exists('WU_NP_NewebPay_Gateway',false),'Missing/foreign dependency must skip private runtime');
    if($mode==='conflict')check(RY_NewebPay_Gateway::FOREIGN_MARKER==='foreign','Do not replace foreign classes');
    echo "WU NewebPay dependency guard passed: $mode\n";exit;
}
$methods=apply_filters('woocommerce_payment_gateways',[]);check(count($methods)===6,'Six primary gateway types preserved');
foreach($methods as $method)check(class_exists($method),'Gateway class missing: '.$method);
$options['wu_np_newebpay_credit_installment']='yes';$methods=apply_filters('woocommerce_payment_gateways',[]);check(count($methods)===12,'All six split installments preserved');
foreach($methods as $method)check(class_exists($method),'Installment missing: '.$method);
$available=['other'=>(object)[], 'wu_np_newebpay_credit'=>(object)[]];
check(array_keys(apply_filters('woocommerce_available_payment_gateways',$available))===['other'],'Services default off');
$options['WU_NP_WT_enabled_newebpay_gateway']='yes';
check(array_keys(apply_filters('woocommerce_available_payment_gateways',$available))===['other'],'No checkout with invalid credentials');
$options['WU_NP_WT_newebpay_gateway_MerchantID']='FIXTURE';$options['WU_NP_WT_newebpay_gateway_HashKey']=str_repeat('k',32);$options['WU_NP_WT_newebpay_gateway_HashIV']=str_repeat('i',16);
check(count(apply_filters('woocommerce_available_payment_gateways',$available))===2,'Configured native payment stays available');
$currency='USD';check(count(apply_filters('woocommerce_available_payment_gateways',$available))===1,'Only TWD supported');
$options['WU_NP_WT_enabled_newebpay_shipping']='yes';
// Shipping settings changed after boot need the next request, never runtime registration on save.
check(WU_NP_WT::get_option('enabled_ecpay_shipping','yes')==='no','No unrelated provider modules');
$r=new ReflectionMethod('WU_NP_NewebPay','args_encrypt');$d=new ReflectionMethod('WU_NP_NewebPay','args_decrypt');if(PHP_VERSION_ID<80100){$r->setAccessible(true);$d->setAccessible(true);}
$encrypted=$r->invoke(null,['MerchantID'=>'FIXTURE','Amt'=>100],str_repeat('k',32),str_repeat('i',16));
parse_str($d->invoke(null,$encrypted,str_repeat('k',32),str_repeat('i',16)),$roundtrip);check($roundtrip===['Amt'=>'100','MerchantID'=>'FIXTURE'],'Original AES/32-byte-padding protocol preserved');
check($d->invoke(null,'invalid',str_repeat('k',32),str_repeat('i',16))===false,'Malformed ciphertext rejected');
check(isset($hooks['woocommerce_api_wu_np_newebpay_callback'])&&!isset($hooks['woocommerce_api_ry_newebpay_callback']),'Callback route isolated');
echo "WU NewebPay offline checks passed: source, 12 methods, scoped callbacks, availability and crypto.\n";
