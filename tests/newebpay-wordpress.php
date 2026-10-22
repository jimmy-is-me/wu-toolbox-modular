<?php
/** Native WooCommerce fixture; transport is mocked, restricted to disposable CI. */
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable CI only');
error_reporting(E_ALL & ~E_DEPRECATED);
add_filter('pre_wp_mail',static function(){return true;});
add_filter('pre_http_request',static function(){throw new RuntimeException('Unexpected HTTP transport in native commerce fixture');},1);
define('WUTM_PATH',dirname(__DIR__).'/');define('WUTM_FILE',WUTM_PATH.'wu-toolbox-modular.php');define('WUTM_URL','https://fixture.test/plugin/');define('WUTM_VERSION','integration');
function np_assert($ok,$label){if(!$ok)throw new RuntimeException($label);}
function np_private($class,$method,...$args){$r=new ReflectionMethod($class,$method);if(PHP_VERSION_ID<80100)$r->setAccessible(true);return $r->invoke(null,...$args);}
require WUTM_PATH.'core/admin-design.php';
update_option('woocommerce_currency','TWD');
foreach(['enabled_newebpay_gateway'=>'yes','enabled_newebpay_shipping'=>'yes','newebpay_gateway_MerchantID'=>'FIXTURE','newebpay_gateway_HashKey'=>str_repeat('k',32),'newebpay_gateway_HashIV'=>str_repeat('i',16),'newebpay_gateway_order_prefix'=>'WU','newebpay_gateway_testmode'=>'yes'] as $key=>$value)update_option('WU_NP_WT_'.$key,$value);
update_option('wc_woomp_enabled_ezpay_invoice','yes');update_option('wc_woomp_ezpay_invoice_issue_mode','manual');update_option('wc_woomp_ezpay_invoice_invalid_mode','manual');
update_option('wc_woomp_ezpay_invoice_merchant_id','FIXTURE');update_option('wc_woomp_ezpay_invoice_hashkey',str_repeat('k',32));update_option('wc_woomp_ezpay_invoice_hashiv',str_repeat('i',16));
function wutm_is_enabled($key){return $key==='newebpay-tools';}
require WUTM_PATH.'modules/newebpay-tools/module.php';wutm_newebpay_boot();
foreach($GLOBALS['wp_filter']['plugins_loaded']->callbacks[30]??[] as $entry){$fn=$entry['function'];if($fn instanceof Closure&&(new ReflectionFunction($fn))->getFileName()===realpath(WUTM_PATH.'core/invoice-modules.php'))$fn();}
np_assert(class_exists('WUEZPAYINVOICE\\APIs\\EzPayInvoiceHandler'),'Restored invoice loaded without Guzzle collision');
$product=new WC_Product_Simple();$product->set_name('WU fixture');$product->set_regular_price('100');$product->set_virtual(false);$product->save();
$order=wc_create_order();$id=$order->get_id();
try {
    $order->add_product($product,1);$order->set_currency('TWD');$order->set_billing_email('fixture@example.test');$order->set_billing_first_name('WU');$order->set_billing_country('TW');$order->set_shipping_country('TW');$order->set_payment_method('wu_np_newebpay_credit');$order->calculate_totals();$order->save();
    $methods=WU_NP_NewebPay_Gateway::add_method([]);foreach($methods as $class){$instance=new $class();np_assert(strpos($instance->id,'wu_np_')===0,'WU gateway identifier');}
    $gateway=new WU_NP_NewebPay_Gateway_Credit();
    ob_start();WU_NP_NewebPay_Gateway_Api::checkout_form($order,$gateway);$html=ob_get_clean();
    np_assert(strpos($html,'https://ccore.newebpay.com/MPG/mpg_gateway')!==false,'Sandbox endpoint used');
    preg_match('/name="TradeInfo" value="([^"]+)"/',$html,$matches);$wire=html_entity_decode($matches[1],ENT_QUOTES);
    parse_str(np_private('WU_NP_NewebPay','args_decrypt',$wire,str_repeat('k',32),str_repeat('i',16)),$payload);
    np_assert($payload['MerchantID']==='FIXTURE'&&(int)$payload['Amt']===100&&strpos($payload['NotifyURL'],'wu_np_newebpay_callback')!==false,'Merchant/amount/callback wire contract');
    $merchantOrder=$payload['MerchantOrderNo'];
    $make=function($changes=[])use($merchantOrder){
        $result=array_merge(['MerchantID'=>'FIXTURE','Amt'=>100,'MerchantOrderNo'=>$merchantOrder,'TradeNo'=>'FIXTURE-TRADE','PaymentType'=>'CREDIT','PayTime'=>'2026-10-22 12:00:00'],$changes);
        $json=wp_json_encode(['Status'=>'SUCCESS','Result'=>$result]);$pad=32-strlen($json)%32;$wire=bin2hex(openssl_encrypt($json.str_repeat(chr($pad),$pad),'aes-256-cbc',str_repeat('k',32),OPENSSL_RAW_DATA|OPENSSL_ZERO_PADDING,str_repeat('i',16)));
        return ['TradeInfo'=>$wire,'TradeSha'=>np_private('WU_NP_NewebPay','generate_hash_value',$wire,str_repeat('k',32),str_repeat('i',16))];
    };
    $valid=$make();np_assert(np_private('WU_NP_NewebPay_Gateway_Response','ipn_request_is_valid',$valid),'Signed exact order accepted');
    foreach([['Amt'=>101],['MerchantID'=>'OTHER'],['MerchantOrderNo'=>'WU'.$id.'TS999999'],['TradeNo'=>'']] as $bad)np_assert(!np_private('WU_NP_NewebPay_Gateway_Response','ipn_request_is_valid',$make($bad)),'Mismatch rejected');
    $tampered=$valid;$tampered['TradeSha']=str_repeat('0',64);np_assert(!np_private('WU_NP_NewebPay_Gateway_Response','ipn_request_is_valid',$tampered),'Signature mismatch rejected');
    $a=wutm_commerce_lock('fixture',$id);np_assert($a&&!wutm_commerce_lock('fixture',$id),'Atomic lock excludes concurrent operation');wutm_commerce_unlock($a);
    $a=wutm_commerce_lock('fixture',$id);np_assert((bool)$a,'Released lock reusable');update_option($a[0],'new-owner:'.(time()+300));wutm_commerce_unlock($a);np_assert(strpos(get_option($a[0]),'new-owner:')===0,'Old holder cannot release replacement lock');delete_option($a[0]);
    add_option($a[0],'expired:'.(time()-10),'',false);$a=wutm_commerce_lock('fixture',$id);np_assert((bool)$a,'Expired CAS takeover');wutm_commerce_unlock($a);
    $events=0;add_action('woocommerce_payment_complete',static function($order_id)use(&$events,$id){if($order_id===$id)++$events;});
    WU_NP_NewebPay_Gateway_Response::doing_callback($valid);$order=wc_get_order($id);np_assert($order->is_paid()&&$events===1,'Native payment completed once');
    WU_NP_NewebPay_Gateway_Response::doing_callback($valid);np_assert($events===1&&count((array)wc_get_order($id)->get_meta('_wu_np_callback_seen'))===1,'Duplicate callback no repeat events');
    np_assert(!np_private('WU_NP_NewebPay_Gateway_Response','ipn_request_is_valid',$make(['TradeNo'=>'OTHER-TRADE'])),'Do not replace paid transaction');
    $order->update_meta_data('_ezpay_invoice_data',['_ezpay_invoice_type'=>'company','_ezpay_invoice_company_name'=>'WU fixture','_ezpay_invoice_tax_id'=>'12345678']);$order->save();
    $handler=new WUEZPAYINVOICE\APIs\EzPayInvoiceHandler();
    $mock=new class {
        public $calls=0,$voids=0,$fail=false;
        public function create($payload){++$this->calls;if($this->fail)throw new RuntimeException('Mock transport failure');np_assert($payload['Category']==='B2B'&&$payload['BuyerUBN']==='12345678','Original invoice request fields');}
        public function info($query){np_assert($query['InvoiceNumber']==='AB12345678','Void lookup fields preserved');return $this;}
        public function invalid($query){++$this->voids;np_assert($query['InvoiceNumber']==='AB12345678'&&$query['RandomNum']==='1234','Void wire fields preserved');return $this;}
        public function isOK(){return true;}
        public function getResponse(){return (object)['code'=>'SUCCESS','message'=>'fixture','result'=>(object)['InvoiceNumber'=>'AB12345678','CreateTime'=>'fixture','MerchantOrderNo'=>'fixture','RandomNum'=>'1234']];}
        public function getResult($field){return $this->getResponse()->result->$field??null;}
    };
    $prop=new ReflectionProperty($handler,'invoice');if(PHP_VERSION_ID<80100)$prop->setAccessible(true);$prop->setValue($handler,$mock);
    $handler->generate_invoice($id);$handler->generate_invoice($id);np_assert($mock->calls===1&&wc_get_order($id)->get_meta('_ezpay_invoice_number')==='AB12345678','Invoice duplicate issue prevented; native meta retained');
    $handler->invalid_invoice($id);$handler->invalid_invoice($id);np_assert($mock->voids===1&&wc_get_order($id)->get_meta('_ezpay_invoice_number')==='','Void preserved without duplicate transport');
    $mock->fail=true;try{$handler->generate_invoice($id);throw new LogicException('Mock failure escaped');}catch(RuntimeException $error){np_assert($error->getMessage()==='Mock transport failure','Expected mock failure');}
    $lock=wutm_commerce_lock('ezpay_invoice',$id);np_assert((bool)$lock,'Invoice lock releases after transport exception');wutm_commerce_unlock($lock);
    $fields=wutm_newebpay_fields('invoice');np_assert(in_array('wc_woomp_ezpay_invoice_hashkey',array_column($fields,'id'),true),'Full invoice settings retained');
    foreach(['services','gateway','shipping','invoice'] as $section){$schema=wutm_newebpay_fields($section);ob_start();WC_Admin_Settings::output_fields($schema);$rendered=ob_get_clean();np_assert(strpos($rendered,'form-table')!==false,'Native settings fields render: '.$section);}
    $save=$GLOBALS['wp_filter']['admin_post_wutm_save_newebpay_tools']->callbacks[10];$save=reset($save)['function'];
    add_filter('wp_die_handler',static function(){return static function(){throw new RuntimeException('fixture-denied');};},999);
    $user=get_current_user_id();wp_set_current_user(0);
    try{$save();throw new LogicException('Unauthorized save accepted');}catch(RuntimeException $error){np_assert($error->getMessage()==='fixture-denied','Settings capability boundary');}
    wp_set_current_user($user);$_POST=['section'=>'services','_wpnonce'=>'invalid'];$_REQUEST=$_POST;
    try{$save();throw new LogicException('Invalid nonce accepted');}catch(RuntimeException $error){np_assert($error->getMessage()==='fixture-denied','Settings nonce boundary');}
    $_POST=['section'=>'unknown','_wpnonce'=>wp_create_nonce('wutm_save_newebpay_tools')];$_REQUEST=$_POST;
    try{$save();throw new LogicException('Unknown section accepted');}catch(RuntimeException $error){np_assert($error->getMessage()==='fixture-denied','Settings section boundary');}
    $_POST=$_REQUEST=[];
    echo "Native WordPress/WooCommerce passed: source methods, sandbox form, callback binding/replay, atomic locks, original invoice payload/meta and duplicate issue guard; no external transports.\n";
} finally { $order->delete(true);$product->delete(true); }
