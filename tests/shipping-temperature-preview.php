<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__);$options=['stt_room_methods'=>['flat_rate:1'],'stt_cold_methods'=>['wu_payuni_shipping_tcat_refrigeration:2']];
function current_user_can(...$args){return true;}function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}function esc_attr($value){return esc_html($value);}
function checked($value,$expect=true,$echo=true){$text=$value===$expect?'checked':'';if($echo)echo $text;return $text;}
function wp_nonce_field(...$args){echo '<input type="hidden" name="wutm_stt_nonce" value="fixture">';}
function wutm_admin_page_open($title,$description){echo '<div class="wrap wutm-ui-root"><h1>'.esc_html($title).'</h1><p>'.esc_html($description).'</p>';}
function wutm_admin_page_close(){echo '</div>';}
class WC_Shipping_Zones {static function get_zones(){return [['zone_id'=>1]];}}
class WC_Shipping_Zone {function __construct(public $id){}function get_zone_name(){return $this->id?'台灣':'其他地區';}function get_shipping_methods(...$args){return $this->id?[new PreviewMethod('flat_rate:1','常溫宅配'),new PreviewMethod('wu_payuni_shipping_tcat_refrigeration:2','冷藏宅配')]:[];}}
class PreviewMethod {function __construct(public $id,public $title){}function get_rate_id(){return $this->id;}function get_title(){return $this->title;}}
require dirname(__DIR__).'/modules/shipping-temperature/temperature.php';
WUTM_Shipping_Temperature::page();
echo '<table id="the-list"><tr id="post-1"><td><span class="wutm-stt-data" data-temp="cold"></span></td></tr><tr id="post-2"><td><span class="wutm-stt-data" data-temp="room"></span></td></tr></table>';
foreach([1,2] as $id){echo '<div id="edit-'.$id.'" class="inline-edit-row">';WUTM_Shipping_Temperature::quick_field();echo '</div>';}
WUTM_Shipping_Temperature::bulk_field();
