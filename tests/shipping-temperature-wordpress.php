<?php
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable fixture only');
define('WUTM_VERSION','fixture');define('WUTM_URL','https://fixture.test/plugin/');
require dirname(__DIR__).'/core/admin-design.php';require dirname(__DIR__).'/modules/shipping-temperature/module.php';
function stt_verify($ok,$message){if(!$ok)throw new RuntimeException($message);}
add_filter('pre_http_request',static fn()=>new WP_Error('fixture','No external requests'));
$saved=[];foreach(['stt_room_methods','stt_cold_methods'] as $key)$saved[$key]=get_option($key,null);
WC()->initialize_session();WC()->initialize_cart();WC()->cart->empty_cart();$products=[];$zone=null;
try {
    foreach(['all','room','cold'] as $temp){$product=new WC_Product_Simple();$product->set_name('WU '.$temp);$product->set_regular_price(100);$product->update_meta_data('_shipping_temperature',$temp);$product->save();$products[$temp]=$product;}
    $room=$products['room'];$cold=$products['cold'];
    $zone=new WC_Shipping_Zone();$zone->set_zone_name('Temperature fixture');$zone->add_location('TW','country');$zone->save();
    $room_id=$zone->add_shipping_method('flat_rate');$cold_id=$zone->add_shipping_method('flat_rate');
    update_option('woocommerce_flat_rate_'.$room_id.'_settings',['title'=>'常溫','cost'=>'50']);update_option('woocommerce_flat_rate_'.$cold_id.'_settings',['title'=>'冷藏','cost'=>'100']);
    update_option('stt_room_methods',['flat_rate:'.$room_id]);update_option('stt_cold_methods',['flat_rate:'.$cold_id]);
    WC()->customer->set_shipping_country('TW');WC()->customer->set_billing_country('TW');
    WC()->cart->add_to_cart($products['all']->get_id());WC()->cart->add_to_cart($cold->get_id());WC()->cart->calculate_totals();
    $package=WC()->cart->get_shipping_packages()[0];$result=WC()->shipping()->calculate_shipping_for_package($package,0);
    stt_verify(array_keys($result['rates'])===['flat_rate:'.$cold_id],'Real zone restricts all+cold to cold method');
    stt_verify(!apply_filters('woocommerce_add_to_cart_validation',true,$room->get_id(),1),'Classic adding room to cold is blocked');
    try{do_action('woocommerce_store_api_validate_add_to_cart',$room,[]);throw new LogicException('Store API conflict was accepted');}catch(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException $error){stt_verify(strpos($error->getMessage(),'常溫')!==false,'Store API returns conflict');}
    // Imported/restored cart can bypass add-to-cart hooks; checkout must still reject it.
    $key=WC()->cart->add_to_cart($room->get_id());do_action('woocommerce_check_cart_items');stt_verify(wc_has_notice(WUTM_Shipping_Temperature::CONFLICT,'error'),'Existing mixed cart checkout blocked');
    $errors=new WP_Error();do_action('woocommerce_store_api_cart_errors',$errors,WC()->cart);stt_verify($errors->has_errors(),'Block checkout existing cart rejected');
    WC()->cart->remove_cart_item($key);wc_clear_notices();
    $_REQUEST=['_shipping_temperature'=>'room','wutm_stt_nonce'=>wp_create_nonce('wutm_stt_product')];WUTM_Shipping_Temperature::save_quick($cold);
    stt_verify(get_post_meta($cold->get_id(),'_shipping_temperature',true)==='room','Native Quick Edit meta saved');
    $changed=WC()->cart->get_shipping_packages()[0];stt_verify($changed!==$package,'Native package cache key changes after edit');
    $result=WC()->shipping()->calculate_shipping_for_package($changed,0);stt_verify(array_keys($result['rates'])===['flat_rate:'.$room_id],'Cached rates switch to room after product edit');
    stt_verify(strpos(WUTM_Shipping_Temperature::notice_fragment([])['.wutm-stt-cart-notice'],'常溫')!==false,'AJAX checkout temperature notice refreshes');
    require dirname(__DIR__).'/modules/product-shipping-restrict/module.php';
    update_post_meta($cold->get_id(),'_wcpsr_allowed_shipping_methods',['flat_rate:'.$cold_id]);
    stt_verify(WC_Product_Shipping_Restrict::filter_package_rates($result['rates'],$changed)===[],'Product restriction and temperature take intersection');
    $_REQUEST=['_stt_bulk'=>'cold','wutm_stt_nonce'=>wp_create_nonce('wutm_stt_product')];WUTM_Shipping_Temperature::save_bulk($room);stt_verify(get_post_meta($room->get_id(),'_shipping_temperature',true)==='cold','Native Bulk Edit saved');
    ob_start();WUTM_Shipping_Temperature::page();$html=ob_get_clean();stt_verify(strpos($html,'stt_room_methods')===false&&strpos($html,'name="room[]"')!==false&&strpos($html,'wutm-ui-root')!==false,'All shipping controls render in common Toolbox page');
    $_POST=['_stt_save'=>'1','room'=>['flat_rate:'.$room_id,['invalid']], 'cold'=>['unknown'],'_wpnonce'=>wp_create_nonce('wutm_stt_settings')];$_REQUEST=$_POST;
    ob_start();WUTM_Shipping_Temperature::page();ob_end_clean();stt_verify(get_option('stt_room_methods')===['flat_rate:'.$room_id]&&get_option('stt_cold_methods')===[],'Settings sanitize unknown and malformed IDs');
    echo "Native temperature shipping passed: real zones, cold/room rates, cart and Store API conflicts, quick/bulk editing, cache and settings.\n";
} finally {$_POST=$_REQUEST=[];WC()->cart->empty_cart();foreach($products as $product)$product->delete(true);if($zone)$zone->delete();foreach($saved as $key=>$value){if($value===null)delete_option($key);else update_option($key,$value,false);}}
