<?php
defined('ABSPATH') || exit;
// Shared utility only; this does not enable or bootstrap the NewebPay module.
require_once WUTM_PATH.'modules/newebpay-tools/locks.php';

function wutm_ps_configured(): bool {
    return (string)get_option('payuni_payment_merchant_id','')!==''&&strlen((string)get_option('payuni_payment_hashkey',''))===32&&strlen((string)get_option('payuni_payment_hashiv',''))===16;
}
function wutm_ps_date(string $offset): string {return (new DateTimeImmutable('now',new DateTimeZone('Asia/Taipei')))->modify($offset)->format('Y-m-d');}
function wutm_ps_package_spec($order): string {
    foreach($order->get_items('shipping') as $item){
        if(strpos($item->get_method_id(),'payuni_shipping_tcat_')!==0)continue;
        $method=WC_Shipping_Zones::get_shipping_method($item->get_instance_id());$spec=$method?(string)$method->get_option('package_spec','1'):'1';
        return in_array($spec,['1','2','3','4'],true)?$spec:'1';
    }
    return '1';
}
function wutm_ps_owned($order): bool {
    if(!$order instanceof WC_Order)return false;
    foreach($order->get_items('shipping') as $item)if(in_array($item->get_method_id(),['payuni_shipping_711_c2c_normal','payuni_shipping_711_c2c_frozen','payuni_shipping_tcat_normal','payuni_shipping_tcat_frozen','payuni_shipping_tcat_refrigerated'],true))return true;
    return false;
}
function wutm_ps_admin_screen(): bool {
    $screen=get_current_screen();return $screen&&in_array($screen->id,['shop_order','edit-shop_order','woocommerce_page_wc-orders'],true);
}
function wutm_ps_create($order,callable $create): void {
    if(is_numeric($order))$order=wc_get_order($order);
    if(!wutm_ps_owned($order)||!wutm_ps_configured())return;
    $lock=wutm_commerce_lock('payuni_shipping',$order->get_id());if(!$lock)return;
    try{$fresh=wc_get_order($order->get_id());if($fresh&&!$fresh->get_meta(\WuDigital\Payuni\Shipping\Utils\OrderMeta::ShipTradeNo))$create($fresh);}
    finally{wutm_commerce_unlock($lock);}
}
/** Verify both the provider hash and GCM authentication before using any data. */
function wutm_ps_verified(array $posted): array {
    if(!wutm_ps_configured())return [];
    foreach(['MerID','EncryptInfo','HashInfo'] as $key)if(!isset($posted[$key])||!is_string($posted[$key]))return [];
    if(!hash_equals((string)get_option('payuni_payment_merchant_id'),$posted['MerID'])||!preg_match('/^[A-Fa-f0-9]{64}$/D',$posted['HashInfo']))return [];
    $cipher=$posted['EncryptInfo'];if(strlen($cipher)>131072||!preg_match('/^(?:[A-Fa-f0-9]{2})+$/D',$cipher))return [];
    if(!hash_equals(\WuDigital\Payuni\Shipping\PayuniShipping::hash_info($cipher),strtoupper($posted['HashInfo'])))return [];
    $data=\WuDigital\Payuni\Shipping\PayuniShipping::decrypt($cipher);
    if(!$data||!isset($data['MerID'])||!is_string($data['MerID'])||!hash_equals($posted['MerID'],$data['MerID']))return [];
    return $data;
}
function wutm_ps_notify_data(array $posted,string $type): array {
    $data=wutm_ps_verified($posted);
    foreach(['Status','ShipTradeNo','ShipStatus','ShipStatusDesc','ShipStatusTime','ShipType','LgsType'] as $key)if(!isset($data[$key])||!is_scalar($data[$key]))return [];
    if($data['Status']!=='SUCCESS'||(string)$data['ShipType']!==$type||($data['LgsType']??'')!==($type==='1'?'C2C':'HOME'))return [];
    $orders=wc_get_orders(['limit'=>2,'meta_query'=>[['key'=>\WuDigital\Payuni\Shipping\Utils\OrderMeta::ShipTradeNo,'value'=>$data['ShipTradeNo'],'compare'=>'=']]]);
    if(count($orders)!==1||!wutm_ps_owned($orders[0])||$orders[0]->get_meta(\WuDigital\Payuni\Shipping\Utils\OrderMeta::ShipTradeNo)!==$data['ShipTradeNo']||(string)$orders[0]->get_meta(\WuDigital\Payuni\Shipping\Utils\OrderMeta::ShipType)!==$type)return [];
    foreach(['OBTNumber','Odno','PartnerId','ValidationNo','FileNo'] as $key)if(isset($data[$key])&&!is_scalar($data[$key]))return [];
    if($type==='2'&&(($data['ApiType']??'')!=='ShipStatus'||empty($data['OBTNumber'])))return [];
    return [$orders[0],$data];
}
function wutm_ps_notify(string $type,callable $callback): void {
    $valid=wutm_ps_notify_data(wp_unslash($_POST),$type);
    if(!$valid)wp_die('物流通知驗證失敗。','',['response'=>403]);
    [$order,$data]=$valid;$lock=wutm_commerce_lock('payuni_shipping',$order->get_id());
    if(!$lock)wp_die('物流通知處理中，請稍後重送。','',['response'=>503]);
    try{
        $order=wc_get_order($order->get_id());
        $fingerprint=hash('sha256',json_encode($data));$seen=(array)$order->get_meta('_wutm_payuni_shipping_seen');
        if(in_array($fingerprint,$seen,true))return;
        $previous=(string)$order->get_meta(\WuDigital\Payuni\Shipping\Utils\OrderMeta::ShipStatusTime);
        if($previous!==''&&strcmp((string)$data['ShipStatusTime'],$previous)<0)return;
        $callback();$order=wc_get_order($order->get_id());$seen[]=$fingerprint;$order->update_meta_data('_wutm_payuni_shipping_seen',array_slice($seen,-20));$order->save();
    }finally{wutm_commerce_unlock($lock);}
}
function wutm_ps_register_guards(): void {
    // WC's legacy store does not natively forward arbitrary meta_query args.
    // Forward only this module's exact trade-number lookup; HPOS handles it natively.
    add_filter('woocommerce_order_data_store_cpt_get_orders_query',static function($query,$vars){
        foreach((array)($vars['meta_query']??[]) as $clause)if(is_array($clause)&&($clause['key']??'')===\WuDigital\Payuni\Shipping\Utils\OrderMeta::ShipTradeNo)$query['meta_query'][]=$clause;
        return $query;
    },10,2);
    $class='WuDigital\\Payuni\\Shipping\\Api\\ShippingResponse';$instance=$class::get_instance();
    foreach(['711'=>'1','tcat'=>'2'] as $name=>$type)foreach(['notify','callback'] as $suffix){
        $hook='woocommerce_api_payuni_shipping_'.$name.'_'.$suffix;$callback=[$instance,'payuni_'.$name.'_receive_update'];
        remove_action($hook,$callback);add_action($hook,static function()use($type,$callback){wutm_ps_notify($type,$callback);});
    }
    add_action('woocommerce_api_payuni_choose_cvs_callback',static function(){
        $data=wutm_ps_verified(wp_unslash($_POST));$map=isset($data['MapJson'])&&is_string($data['MapJson'])?json_decode($data['MapJson'],true):null;
        if(($data['Status']??$_POST['Status']??'')!=='SUCCESS'||!is_array($map))wp_die('選店資料驗證失敗。','',['response'=>403]);
        foreach(['StoreType','StoreID','StoreName','Address'] as $field)if(empty($map[$field])||!is_scalar($map[$field]))wp_die('選店資料不完整。','',['response'=>400]);
        if(isset($_REQUEST['order_id'])){
            $id=absint($_REQUEST['order_id']);
            if(!current_user_can('edit_shop_order',$id)||!wp_verify_nonce($_REQUEST['_wpnonce']??'','wutm_payuni_change_store_'.$id)||!wutm_ps_owned(wc_get_order($id)))wp_die('沒有更換門市權限。','',['response'=>403]);
        }
        // Never post customer store information to an arbitrary cookie URL.
        $_COOKIE['payuni_checkout_url']=wp_validate_redirect($_COOKIE['payuni_checkout_url']??'',wc_get_checkout_url());
    },1);
    foreach(['update_delivery_status','payuni_shipping_print_label','payuni_shipping_download_label'] as $action)add_action('wp_ajax_'.$action,static function()use($action){
        check_ajax_referer('payuni-shipping-order','security');
        $ids=$action==='update_delivery_status'?($_POST['post_id']??''):($_GET['orderids']??'');
        if(!is_scalar($ids)||!preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*)*$/D',(string)$ids))wp_die('訂單編號不正確。','',['response'=>400]);
        foreach(explode(',',(string)$ids) as $id){$order=wc_get_order((int)$id);if(!current_user_can('edit_shop_order',(int)$id)||!wutm_ps_owned($order)||!$order->get_meta(\WuDigital\Payuni\Shipping\Utils\OrderMeta::ShipTradeNo))wp_die('無法操作此物流訂單。','',['response'=>403]);
            if($action!=='update_delivery_status'&&(string)$order->get_meta(\WuDigital\Payuni\Shipping\Utils\OrderMeta::ShipType)!==(string)($_GET['service']??''))wp_die('物流服務不符。','',['response'=>400]);}
    },1);
}
/** Keep useful event headings but never log raw payloads, addresses or secrets. */
function wutm_ps_log_message($message): string {
    $heading=preg_split('/[:\r\n]/',(string)$message,2)[0];return substr($heading,0,100).' [物流內容已隱藏]';
}
