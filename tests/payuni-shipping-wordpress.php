<?php
/** Native disposable WooCommerce, mocked transport only. */
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable CI only');
error_reporting(E_ALL & ~E_DEPRECATED);
use WuDigital\Payuni\Shipping\PayuniShipping as PS;
use WuDigital\Payuni\Shipping\Api\ShippingRequest as Request;
use WuDigital\Payuni\Shipping\Utils\OrderMeta as Meta;
function ps_native($ok,$message){if(!$ok)throw new RuntimeException($message);}
define('WUTM_PATH',dirname(__DIR__).'/');define('WUTM_URL','https://fixture.test/plugin/');define('WUTM_VERSION','integration');
require WUTM_PATH.'core/admin-design.php';
update_option('woocommerce_currency','TWD');
foreach(['merchant_id'=>'FIXTURE','hashkey'=>str_repeat('k',32),'hashiv'=>str_repeat('i',16)] as $key=>$value)update_option('payuni_payment_'.$key,$value);
delete_option('payuni_shipping_testmode_enabled');update_option('payuni_shipping_711_create_order_enabled','no');
add_filter('pre_wp_mail',static function(){return true;});
require WUTM_PATH.'modules/payuni-shipping/module.php';WUTM_PAYUNi_Shipping_Module::initialize();
ps_native(PS::$testmode&&PS::$api_url==='https://sandbox-api.payuni.com.tw/api','New install defaults to sandbox without DB write');
$methods=apply_filters('woocommerce_shipping_methods',[]);$ids=array_filter(array_keys($methods),static function($id){return strpos($id,'payuni_shipping_')===0;});ps_native(count($ids)===5,'All five methods retained');
foreach($ids as $method){$instance=new $methods[$method](0);ps_native(isset($instance->instance_form_fields['cost'],$instance->instance_form_fields['free_shipping_requires']),'Native instance rate/free-shipping fields: '.$method);}
$schema=wutm_ps_settings();$fields=array_column($schema,null,'id');ps_native(count($schema)>20&&$fields['payuni_payment_hashkey']['type']==='password','Every source setting retained with masked secrets');
ps_native(isset($fields['payuni_shipping_order_status_pickuped'],$fields['payuni_shipping_tcat_delivery_time'],$fields['payuni_shipping_tcat_estimate_shipping_date']),'Status and TCat options retained');
$product=new WC_Product_Simple();$product->set_name('WU fixture');$product->set_regular_price('100');$product->save();
$order=wc_create_order();$foreign=wc_create_order();$id=$order->get_id();$httpCalls=[];$transport='create';
$intercept=static function($pre,$args,$url)use(&$httpCalls,&$transport){
    if(strpos($url,'https://sandbox-api.payuni.com.tw/api/')!==0)throw new RuntimeException('Unexpected external transport');
    $data=PS::decrypt($args['body']['EncryptInfo']??'');ps_native($data&&($data['MerID']??'')==='FIXTURE','Original encrypted request data');
    ps_native(hash_equals(PS::hash_info($args['body']['EncryptInfo']),$args['body']['HashInfo']),'Original request hash');$httpCalls[]=[$url,$data,$args['body']['Version']];
    if($transport==='failure')return new WP_Error('fixture_timeout','Mock timeout');
    if($transport==='pdf')return ['headers'=>['content-type'=>'application/pdf; charset=binary'],'body'=>"%PDF-1.4\nfixture",'response'=>['code'=>200]];
    $result=['Status'=>'SUCCESS','MerID'=>'FIXTURE','ShipTradeNo'=>'SHIP-FIXTURE','ShipStatus'=>'31','ShipStatusDesc'=>'配送中','ShipStatusTime'=>'2026-10-22 12:00:00'];
    if($transport==='bad-query')$result['ShipTradeNo']='OTHER';
    return ['headers'=>[],'body'=>wp_json_encode(['EncryptInfo'=>PS::encrypt($result)]),'response'=>['code'=>200]];
};
add_filter('pre_http_request',$intercept,1,3);
try{
    $order->add_product($product,1);$order->set_currency('TWD');$order->set_payment_method('cod');$order->set_billing_email('fixture@example.test');$order->set_shipping_country('TW');$order->set_shipping_phone('0912345678');
    $shipping=new WC_Order_Item_Shipping();$shipping->set_method_id('payuni_shipping_711_c2c_normal');$order->add_item($shipping);$order->calculate_totals();$order->save();
    $otherShipping=new WC_Order_Item_Shipping();$otherShipping->set_method_id('flat_rate');$foreign->add_item($otherShipping);$foreign->save();
    PS::payuni_save_order_shipping_meta($order,['payuni_storeid'=>'123456','payuni_storename'=>'測試門市','payuni_storeaddress'=>'測試地址','payuni_shiptype'=>'1']);
    $order=wc_get_order($id);ps_native($order->get_meta(Meta::GoodsType)==='1'&&$order->get_meta(Meta::ShipType)==='1','Original checkout metadata retained');
    Request::payuni_create_shipping($id);ps_native(!$httpCalls,'7-ELEVEN default off');
    update_option('payuni_shipping_711_create_order_enabled','yes');Request::payuni_create_shipping($id);Request::payuni_create_shipping($id);
    ps_native(count($httpCalls)===1&&wc_get_order($id)->get_meta(Meta::ShipTradeNo)==='SHIP-FIXTURE','Native create persists trade, no duplicate request');
    ps_native(substr($httpCalls[0][0],-16)==='/logistics/trade'&&$httpCalls[0][1]['ServiceType']==='1'&&$httpCalls[0][2]==='1.1','Original C2C/COD payload and version');
    $notification=['MerID'=>'FIXTURE','Status'=>'SUCCESS','ShipTradeNo'=>'SHIP-FIXTURE','ShipType'=>'1','LgsType'=>'C2C','ShipStatus'=>'32','ShipStatusDesc'=>'到達門市','ShipStatusTime'=>'2026-10-22 13:00:00'];
    $sign=static function($data){$cipher=PS::encrypt($data);return ['MerID'=>'FIXTURE','Status'=>'SUCCESS','EncryptInfo'=>$cipher,'HashInfo'=>PS::hash_info($cipher)];};
    $posted=$sign($notification);ps_native(count(wutm_ps_notify_data($posted,'1'))===2,'Signed exact-order notification accepted with multiple orders present');
    $tampered=$posted;$tampered['HashInfo']=str_repeat('0',64);ps_native(!wutm_ps_verified($tampered),'Tampered hash rejected');
    ps_native(!wutm_ps_notify_data($sign(array_replace($notification,['ShipTradeNo'=>'OTHER'])),'1'),'Unknown trade rejected');
    ps_native(!wutm_ps_notify_data($sign(array_replace($notification,['MerID'=>'OTHER'])),'1'),'Wrong merchant rejected');
    ps_native(!wutm_ps_notify_data($posted,'2'),'Wrong shipping service rejected');
    $_POST=$posted;$callback=['WuDigital\\Payuni\\Shipping\\Api\\ShippingResponse','payuni_711_receive_update'];wutm_ps_notify('1',$callback);
    $notes=count(wc_get_order_notes(['order_id'=>$id]));wutm_ps_notify('1',$callback);ps_native(count(wc_get_order_notes(['order_id'=>$id]))===$notes,'Native callback replay does not duplicate notes/status transitions');
    $_POST=$sign(array_replace($notification,['ShipStatus'=>'21','ShipStatusTime'=>'2026-10-21 13:00:00']));wutm_ps_notify('1',$callback);ps_native(wc_get_order($id)->get_meta(Meta::ShipStatus)==='32','Stale notification does not downgrade status');
    $before=wc_get_order($id)->get_data();PS::payuni_save_order_shipping_meta($foreign,['payuni_storeid'=>'123','payuni_storename'=>'外部','payuni_storeaddress'=>'外部']);ps_native(!$foreign->get_meta(Meta::StoreId),'Foreign order data preserved');
    $build=new ReflectionMethod(Request::class,'build_request_args');if(PHP_VERSION_ID<80100)$build->setAccessible(true);
    $shipping->set_method_id('payuni_shipping_tcat_refrigerated');$shipping->save();$order=wc_get_order($id);PS::payuni_save_order_shipping_meta($order,[]);$order=wc_get_order($id);
    $tcat=$build->invoke(null,$order);ps_native($tcat['LgsType']==='HOME'&&$tcat['ShipType']==='2'&&$tcat['GoodsType']==='3'&&isset($tcat['ConsigneeAddress'],$tcat['ProdDesc'],$tcat['DeliveryTimeTag']),'Original TCat refrigerated request fields');
    ps_native($order->get_meta(Meta::PackageSpec)==='1','TCat package spec uses actual instance/default code');
    $shipping->set_method_id('payuni_shipping_711_c2c_frozen');$shipping->save();$order=wc_get_order($id);PS::payuni_save_order_shipping_meta($order,['payuni_storeid'=>'123456','payuni_storename'=>'冷凍門市','payuni_storeaddress'=>'測試地址','payuni_shiptype'=>'1']);$order=wc_get_order($id);
    ps_native($build->invoke(null,$order)['GoodsType']==='2','Frozen C2C goods type preserved');
    $phone=['shipping'=>['shipping_phone'=>['label'=>'原電話','required'=>true,'class'=>['foreign']]]];ps_native(PS::get_shipping_cvs_field($phone)['shipping']['shipping_phone']===$phone['shipping']['shipping_phone'],'Foreign checkout phone requirements preserved');
    $_POST=['shipping_method'=>['flat_rate:1']];$postBefore=$_POST;PS::payuni_shipping_fields_validation();ps_native($_POST===$postBefore,'Foreign checkout validation does not inject country');
    $order=wc_get_order($id);$order->delete_meta_data(Meta::ShipTradeNo);$order->save();$transport='failure';Request::payuni_create_shipping($id);ps_native(!wc_get_order($id)->get_meta(Meta::ShipTradeNo),'Transport error does not fabricate a trade');$lock=wutm_commerce_lock('payuni_shipping',$id);ps_native((bool)$lock,'Lock released after failure');wutm_commerce_unlock($lock);
    $timezone=date_default_timezone_get();ps_native((bool)preg_match('/^\d{4}-\d{2}-\d{2}$/',wutm_ps_date('+1 day'))&&date_default_timezone_get()===$timezone,'Label dates do not change global timezone');
    $save=$GLOBALS['wp_filter']['admin_post_wutm_save_payuni_shipping']->callbacks[10];$save=reset($save)['function'];
    add_filter('wp_die_handler',static function(){return static function(){throw new RuntimeException('fixture-denied');};},999);
    // Exercise source query and label download with real WP HTTP response helpers.
    $order=wc_get_order($id);$order->update_meta_data(Meta::ShipTradeNo,'SHIP-FIXTURE');$order->save();
    $_POST=['post_id'=>$id,'security'=>wp_create_nonce('payuni-shipping-order')];$_REQUEST=$_POST;$transport='bad-query';$previousStatus=$order->get_meta(Meta::ShipStatus);
    ob_start();try{Request::payuni_ajax_query_delivery_status();}catch(RuntimeException $error){ps_native($error->getMessage()==='fixture-denied','Query JSON terminates via native WP handler');}$json=json_decode(ob_get_clean(),true);
    ps_native($json['success']===false&&wc_get_order($id)->get_meta(Meta::ShipStatus)===$previousStatus,'Mismatched query cannot overwrite shipment');
    $transport='query';ob_start();try{Request::payuni_ajax_query_delivery_status();}catch(RuntimeException $error){ps_native($error->getMessage()==='fixture-denied','Valid query JSON terminates');}$json=json_decode(ob_get_clean(),true);ps_native($json['success']===true,'Original status query succeeds');
    $shipping->set_method_id('payuni_shipping_tcat_normal');$shipping->save();$order=wc_get_order($id);$order->update_meta_data(Meta::ShipType,'2');$order->update_meta_data(Meta::FileNo,'PDF-FIXTURE');$order->save();
    $_GET=['orderids'=>(string)$id,'service'=>'2','security'=>wp_create_nonce('payuni-shipping-order')];$_REQUEST=$_GET;$transport='pdf';ob_start();try{Request::payuni_download_label();}catch(RuntimeException $error){ps_native($error->getMessage()==='fixture-denied','PDF terminates through WP handler');}$pdf=ob_get_clean();ps_native(strpos($pdf,'%PDF-1.4')===0,'Original PDF download works with native response headers');
    $user=get_current_user_id();wp_set_current_user(0);try{$save();throw new LogicException('Unauthorized save accepted');}catch(RuntimeException $error){ps_native($error->getMessage()==='fixture-denied','Settings capability enforced');}wp_set_current_user($user);
    $_POST=['_wpnonce'=>'invalid'];$_REQUEST=$_POST;try{$save();throw new LogicException('Bad nonce accepted');}catch(RuntimeException $error){ps_native($error->getMessage()==='fixture-denied','Settings nonce enforced');}
    echo "Native PAYUNi shipping passed: five methods/settings, sandbox wire payload, checkout metadata, signed exact-order callbacks, replay/stale protection, foreign-provider preservation, transport failure and settings boundaries; no live requests.\n";
}finally{$_POST=$_GET=$_REQUEST=[];remove_filter('pre_http_request',$intercept,1);$order->delete(true);$foreign->delete(true);$product->delete(true);}
