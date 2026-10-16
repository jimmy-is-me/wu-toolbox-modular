<?php
if(PHP_SAPI!=='cli')exit;
ob_start();require __DIR__.'/wc-shipping-merge.php';ob_end_clean();
function wp_unslash($v){return $v;}
function sanitize_text_field($v){return trim(strip_tags($v));}
function wc_add_notice($text,$type){$GLOBALS['notices'][]=$text;}
function is_checkout(){return $GLOBALS['checkout365']??true;}
function WC(){return $GLOBALS['wc365'];}
$wc365=(object)['session'=>new class {
 public $methods=[];
 function get($key,$default=[]){return $key==='chosen_shipping_methods'?$this->methods:$default;}
}];
$optimizer=(new ReflectionClass('WU_WooCommerce_Optimizer'))->newInstanceWithoutConstructor();
$base=['shipping'=>['shipping_phone'=>['label'=>'收件者電話','required'=>false,'type'=>'tel','validate'=>['phone'],'custom_attributes'=>['data-other'=>'keep']]]];
$assert=function($ok,$label){if(!$ok)throw new RuntimeException($label);};
foreach(['seven_eleven_pickup:3','cvs_711_shipping:9'] as $rate){
 $_POST=['shipping_method'=>[$rate]];
 $fields=$optimizer->modify_711_checkout_fields($base);
 $assert($fields['shipping']['shipping_phone']['required']===true,'Pickup shipping phone required');
 $assert($fields['shipping']['shipping_phone']['custom_attributes']['data-wutm-phone-required-default']==='0','Non-pickup default retained');
 $assert($fields['shipping']['shipping_phone']['custom_attributes']['data-other']==='keep','Other attributes preserved');
 $_POST+=['cvs_store_id'=>'123456','cvs_store_name'=>'門市','cvs_store_address'=>'地址','billing_phone'=>'+886912345678'];
 foreach([null,'','   ',['0912345678']] as $phone){
  $notices=[];$_POST['shipping_phone']=$phone;$optimizer->validate_711_fields();
  $assert(count($notices)===1&&str_contains($notices[0],'收件者聯絡電話'),'Billing phone does not bypass missing/malformed shipping phone');
 }
 $notices=[];$_POST['shipping_phone']='+886912345678';$optimizer->validate_711_fields();$assert(!$notices,'International-format shipping phone accepted');
 // An unchecked separate-address checkbox must not bypass the explicit pickup validation.
 $_POST['ship_to_different_address']='0';$_POST['shipping_phone']='';$notices=[];$optimizer->validate_711_fields();$assert(count($notices)===1,'Separate-address bypass blocked');
}
$_POST=['shipping_method'=>['flat_rate:1'],'shipping_phone'=>''];$notices=[];$optimizer->validate_711_fields();$assert(!$notices,'Other shipping unchanged');
$assert($optimizer->modify_711_checkout_fields($base)['shipping']['shipping_phone']['required']===false,'Normal delivery stays optional');
$required=$base;$required['shipping']['shipping_phone']['required']=true;
$assert($optimizer->modify_711_checkout_fields($required)['shipping']['shipping_phone']['required']===true,'Third-party normal required setting preserved');
$_POST=['post_data'=>http_build_query(['shipping_method'=>['flat_rate:1','seven_eleven_pickup:2']])];
$assert($optimizer->modify_711_checkout_fields($base)['shipping']['shipping_phone']['required']===true,'Serialized update_checkout supports multiple packages');
$_POST=[];$wc365->session->methods=['seven_eleven_pickup:2'];
$assert($optimizer->modify_711_checkout_fields($base)['shipping']['shipping_phone']['required']===true,'Initial session selection');
$_POST=['shipping_method'=>['flat_rate:1']];
$assert($optimizer->modify_711_checkout_fields($base)['shipping']['shipping_phone']['required']===false,'Posted normal method overrides stale session');
$mode=$argv[1]??'policy';
if($mode==='script'){ob_start();$optimizer->add_711_toggle_script();$output=ob_get_clean();preg_match('/<script>([\s\S]*?)<\/script>/',$output,$match);echo json_encode(['script'=>$match[1]]);exit;}
$checkout365=false;ob_start();$optimizer->add_711_toggle_script();$assert(ob_get_clean()==='','Other pages receive no checkout script');
echo "7-11 phone checks passed: explicit recipient required, billing cannot bypass, malformed/missing values, methods, packages and normal shipping preservation.\n";
