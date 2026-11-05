<?php
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable fixture only');
require_once dirname(__DIR__).'/core/woocommerce-tools.php';
require dirname(__DIR__).'/modules/product-sales-count/module.php';
function v396_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
$optimizer=(new ReflectionClass('WU_WooCommerce_Optimizer'))->newInstanceWithoutConstructor();
$p=new WC_Product_Simple();$p->set_name('Quick sales fixture');$p->set_total_sales(7);$p->save();
$uid=get_current_user_id();
try{
 $_POST=['_wu_sales_offset'=>'-2'];do_action('woocommerce_product_quick_edit_save',$p);
 v396_assert((int)get_post_meta($p->get_id(),'_wu_sales_offset',true)===-2&&wutm_sales_count_value($p->get_id())===5,'Quick edit persisted offset; real sales preserved');
 wp_set_current_user(0);$_POST=['_wu_sales_offset'=>'900'];do_action('woocommerce_product_quick_edit_save',$p);v396_assert((int)get_post_meta($p->get_id(),'_wu_sales_offset',true)===-2,'Quick edit permission');wp_set_current_user($uid);
 $base=['billing'=>WC()->countries->get_address_fields('TW','billing_'),'shipping'=>WC()->countries->get_address_fields('TW','shipping_')];
 $_POST=[];$normal=$optimizer->customize_taiwan_address_fields($base);
 v396_assert(!isset($normal['billing']['billing_company']),'Company removed');
 foreach(['state','city','postcode','address_1'] as $part)v396_assert((bool)($normal['billing']['billing_'.$part]['required']??false)===(bool)($base['billing']['billing_'.$part]['required']??false),'Default billing requirement preserved '.$part);
 foreach([['ship_to_different_address'=>'1'],['post_data'=>'ship_to_different_address=1']] as $post){
  $_POST=$post;$fields=$optimizer->customize_taiwan_address_fields($base);
  foreach(['country','state','city','postcode','address_1'] as $part)v396_assert($fields['billing']['billing_'.$part]['required']===false,'Separate-address billing optional '.$part);
  $checkout=WC()->checkout();$prop=new ReflectionProperty(WC_Checkout::class,'fields');$prop->setAccessible(true);$old=$prop->getValue($checkout);$prop->setValue($checkout,['billing'=>$fields['billing']]);
  $data=[];foreach($fields['billing'] as $key=>$field)$data[$key]='';$data['billing_first_name']='測試';$data['billing_country']='TW';
  $errors=new WP_Error();$validate=new ReflectionMethod(WC_Checkout::class,'validate_posted_data');$validate->setAccessible(true);$args=[&$data,&$errors];$validate->invokeArgs($checkout,$args);$prop->setValue($checkout,$old);
  foreach($errors->get_error_codes() as $code)v396_assert(!preg_match('/billing_(state|city|postcode|address_1|country)/',$code),'Native checkout does not reject hidden address '.$code);
 }
 $_POST=[];v396_assert(!empty($optimizer->customize_taiwan_address_fields($base)['billing']['billing_address_1']['required']),'Unchecking restores required');
 v396_assert(str_contains($optimizer->taiwan_country_label('<input type="hidden" value="TW">','billing_country',[],'TW'),'台灣'),'Visible fixed country');
 echo "v3.9.6 native checkout passed: saved quick-edit adjustment, permissions, real sales preserved, fixed Taiwan, hidden company and separate-address native validation.\n";
}finally{$_POST=[];wp_set_current_user($uid);$p->delete(true);}
