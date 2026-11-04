<?php
define('ABSPATH',__DIR__);class WooCommerce{}
$options=['wutm_product_list_columns'=>['cb'=>'選取','thumb'=>'圖片','name'=>'名稱','sku'=>'貨號','foreign'=>'外掛欄位'],'pbm_registry'=>['product'=>['woocommerce-product-data'=>['title'=>'商品資料','context'=>'normal']]]];
function is_admin(){return true;}function is_multisite(){return false;}function current_user_can(...$a){return true;}function add_action(...$a){}function add_filter(...$a){}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}function wp_parse_args($a,$b){return array_merge($b,$a);}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function esc_attr($v){return esc_html($v);}function esc_url($v){return esc_html($v);}
function wp_nonce_field(...$a){echo '<input type="hidden" name="_wpnonce" value="fixture">';}function admin_url($s){return '/wp-admin/'.$s;}
function checked($a,$b=true,$echo=true){$s=$a==$b?' checked':'';if($echo)echo $s;return $s;}function selected($a,$b,$echo=true){$s=$a==$b?' selected':'';if($echo)echo $s;return $s;}
function get_post_types(...$a){return ['product'=>(object)['name'=>'product','labels'=>(object)['name'=>'商品','singular_name'=>'商品']]];}function get_post_type_object($t){return get_post_types()[$t]??null;}
function wutm_admin_page_open($title,$desc){echo '<div class="wutm-ui-root"><h1>'.esc_html($title).'</h1><p>'.esc_html($desc).'</p>';}function wutm_admin_page_close(){echo '</div>';}
require dirname(__DIR__).'/modules/product-list-layout/module.php';require dirname(__DIR__).'/modules/postbox-manager/module.php';
if(($argv[1]??'')==='postbox')WUTM_Postbox_Manager::page();else WUTM_Product_List_Layout::page();
