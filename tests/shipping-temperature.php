<?php
define('ABSPATH',__DIR__);define('WUTM_URL','/');define('WUTM_VERSION','fixture');
$mode=$argv[1]??'enabled';$meta=[1=>'all',2=>'room',3=>'cold'];$parents=[4=>2];$options=[];$hooks=[];$notices=[];$allowed=true;$nonce=true;
if($mode!=='no-woocommerce'){class WooCommerce{}}
function add_action($name,$callback,...$args){$GLOBALS['hooks'][$name][]=$callback;}
function add_filter(...$args){add_action(...$args);}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function get_post_meta($id,...$args){return $GLOBALS['meta'][$id]??'';}
function wp_get_post_parent_id($id){return $GLOBALS['parents'][$id]??0;}
function WC(){return (object)['cart'=>$GLOBALS['cart']];}
class TestCart {public $items=[];function get_cart(){return $this->items;}function is_empty(){return !$this->items;}}
function wc_add_notice($message,$type){$GLOBALS['notices'][]=$message;}
function wc_has_notice($message,...$args){return in_array($message,$GLOBALS['notices'],true);}
function apply_filters($name,$value,...$args){return $value;}
function verify($ok,$message){if(!$ok)throw new RuntimeException($message);}
function current_user_can(...$args){return $GLOBALS['allowed'];}
function wp_verify_nonce(...$args){return $GLOBALS['nonce'];}
function sanitize_text_field($value){return strip_tags($value);}
function wp_unslash($value){return $value;}
function wc_get_product($id){return new TestProduct($id);}
class TestProduct {function __construct(public $id){}function update_meta_data($key,$value){$GLOBALS['meta'][$this->id]=$value;}function save_meta_data(){}function get_id(){return $this->id;}}
$cart=new TestCart();
require dirname(__DIR__).'/modules/shipping-temperature/module.php';
if($mode==='no-woocommerce'){verify(!$hooks,'No WooCommerce means no runtime hooks');echo "Temperature dependency guard passed.\n";exit;}
$class='WUTM_Shipping_Temperature';
$all=['product_id'=>1,'quantity'=>1];$room=['product_id'=>2,'quantity'=>1];$cold=['product_id'=>3,'quantity'=>1];
verify($class::temp(999)==='all'&&$class::temp(4)==='room','Default and variation parent inheritance');
foreach([[[$all],'room'],[[$all,$cold],'cold'],[[$all,$room],'room'],[[$room,$cold],'conflict']] as [$items,$expected])verify($class::cart_type($items)===$expected,'Cart temperature '.$expected);
$cart->items=[$room];verify(!$class::validate_add(true,3,1)&&$class::validate_add(true,1,1)&&!$class::validate_add(false,1,1),'Conflict block, flexible item and original validation preserved');
$notices=[];$cart->items=[$room,$cold];$class::check_cart();$class::check_cart();verify(count($notices)===1,'Restored mixed cart blocked without duplicate checkout notices');
$options['stt_cold_methods']=['flat_rate:2'];$rates=['flat_rate:2'=>(object)[],'flat_rate:20'=>(object)[],'flat_rate:2:cold'=>(object)[],'other:1'=>(object)[]];
verify(array_keys($class::filter_rates($rates,['contents'=>[$cold]]))===['flat_rate:2','flat_rate:2:cold'],'Instance exactness and provider suffix support');
verify($class::filter_rates($rates,['contents'=>[$room]])===$rates,'Original empty allowed list remains unrestricted');
verify($class::filter_rates($rates,['contents'=>[$room,$cold]])===[],'Mixed cart cannot receive a shipment');
$before=$class::packages([['contents'=>[$cold]]]);$options['stt_cold_methods']=['other:1'];verify($before!==$class::packages([['contents'=>[$cold]]]),'Mapping changes affect shipping cache key');
$before=$class::packages([['contents'=>[$cold]]]);$meta[3]='room';verify($before!==$class::packages([['contents'=>[$cold]]]),'Product temperature changes affect cache key');
$_REQUEST=['_shipping_temperature'=>'cold','wutm_stt_nonce'=>'fixture'];$nonce=false;$class::save_product(2);verify($meta[2]==='room','Bad nonce rejected');
$nonce=true;$allowed=false;$class::save_product(2);verify($meta[2]==='room','Missing edit capability rejected');
$allowed=true;$class::save_quick(new TestProduct(2));verify($meta[2]==='cold','Quick Edit saves original metadata key');
$_REQUEST=['_stt_bulk'=>'','wutm_stt_nonce'=>'fixture'];$class::save_bulk(new TestProduct(2));verify($meta[2]==='cold','Bulk no-change retained');
$_REQUEST['_stt_bulk']='room';$class::save_bulk(new TestProduct(2));verify($meta[2]==='room','Bulk Edit persists chosen temperature');
define('WC_STT_VERSION','standalone');verify($class::filter_rates($rates,['contents'=>[$cold]])===$rates,'Late standalone conflict disables own filtering');
$_REQUEST['_shipping_temperature']='cold';$class::save_product(2);verify($meta[2]==='room','Standalone conflict cannot duplicate writes');
foreach(['temperature.php','admin.js','admin.css'] as $file)verify(!preg_match('/\p{Extended_Pictographic}/u',file_get_contents(dirname(__DIR__).'/modules/shipping-temperature/'.$file)),'No emoji: '.$file);
echo "Temperature checks passed: full cart modes, shipping mappings/cache, edit guards, legacy data and standalone isolation.\n";
