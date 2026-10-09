<?php
/** CLI-only tests / visual fixtures for WC badge module contracts and scope. */
if(PHP_SAPI!=='cli'){exit;}
define('ABSPATH',__DIR__.'/');define('WUTM_VERSION','test');
$hooks=[];$styles=[];$scripts=[];$inlineStyles=[];$inlineScripts=[];$options=[];$taxonomy=false;$assignments=[7=>[1,2,3],8=>[]];
$caps=true;$ajax=false;$head=false;$single=false;$terms=[];$termMeta=[];$writes=0;$queries=0;$screen=(object)['post_type'=>'post','taxonomy'=>''];$menus=[];
class WooCommerce{}
class WC_Product{private $id;public function __construct($id){$this->id=$id;}public function get_id(){return $this->id;}public function is_type($type){return $this->id===9&&$type==='variation';}public function get_parent_id(){return 7;}}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);}
function add_action($key,$fn,...$args){$GLOBALS['hooks'][$key][]=$fn;}
function add_filter(...$args){add_action(...$args);}
function apply_filters($key,$value,...$args){return $GLOBALS['filterFlags'][$key]??$value;}
function add_shortcode($key,$fn){$GLOBALS['shortcodes'][$key]=$fn;}
function register_taxonomy($key,$objects,$args){$GLOBALS['taxonomy']=$args;$GLOBALS['taxKey']=$key;}
function taxonomy_exists($tax){return (bool)$GLOBALS['taxonomy'];}
function current_user_can(...$args){return $GLOBALS['caps'];}
function wp_doing_ajax(){return $GLOBALS['ajax'];}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
function term_exists($slug,...$args){foreach($GLOBALS['terms'] as $term)if($term->slug===$slug)return $term->term_id;return false;}
function wp_insert_term($name,$tax,$args){$id=count($GLOBALS['terms'])+1;$GLOBALS['terms'][$id]=(object)['term_id'=>$id,'name'=>$name,'slug'=>$args['slug']];return ['term_id'=>$id];}
function is_wp_error($x){return false;}
function get_term($id,$tax){return $GLOBALS['terms'][$id]??false;}
function get_term_meta($id,$key,...$args){return $GLOBALS['termMeta'][$id][$key]??'';}
function update_term_meta($id,$key,$value){$GLOBALS['termMeta'][$id][$key]=$value;}
function update_termmeta_cache($ids){$GLOBALS['primed'][]=$ids;}
function get_terms($args){$GLOBALS['queries']++;return array_values($GLOBALS['terms']);}
function get_the_terms($id,$tax){$GLOBALS['queries']++;return array_values(array_filter($GLOBALS['terms'],static fn($t)=>in_array($t->term_id,$GLOBALS['assignments'][$id]??[],true)));}
function wp_set_object_terms($id,$ids,$tax,$append){$GLOBALS['assignments'][$id]=$ids;$GLOBALS['writes']++;}
function wp_list_pluck($list,$key){return array_map(static fn($x)=>$x->$key,$list);}
function wp_verify_nonce($value,...$args){return $value==='valid';}
function wp_unslash($x){return $x;}
function sanitize_text_field($x){return trim(strip_tags($x));}
function sanitize_hex_color($x){return preg_match('/^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/i',$x)?$x:null;}
function absint($x){return abs((int)$x);}
function esc_html($x){return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');}
function esc_attr($x){return esc_html($x);}
function esc_url($x){return esc_html($x);}
function checked($a,$b=true,$echo=true){$v=$a===$b?'checked':'';if($echo)echo $v;return $v;}
function wp_nonce_field($action,$key,...$args){echo '<input type="hidden" name="'.esc_attr($key).'" value="valid">';}
function wp_is_post_revision($id){return false;}
function admin_url($path){return '/wp-admin/'.$path;}
function wp_json_encode($x){return json_encode($x);}
function shortcode_atts($defaults,$values,...$args){return array_merge($defaults,array_intersect_key($values,$defaults));}
function wc_get_product($id){return in_array($id,[7,8,9],true)?new WC_Product($id):false;}
function get_the_ID(){return 7;}
function get_queried_object_id(){return 7;}
function is_product(){return $GLOBALS['single'];}
function get_current_screen(){return $GLOBALS['screen'];}
function wp_register_style(...$args){}
function wp_enqueue_style($key){$GLOBALS['styles'][]=$key;}
function wp_add_inline_style($key,$css){$GLOBALS['inlineStyles'][$key][]=$css;}
function wp_register_script(...$args){}
function wp_enqueue_script($key){$GLOBALS['scripts'][]=$key;}
function wp_add_inline_script($key,$js,...$args){$GLOBALS['inlineScripts'][$key][]=$js;}
function did_action($key){return $key==='wp_print_styles'&&$GLOBALS['head']?1:0;}
function add_meta_box(...$args){$GLOBALS['boxArgs']=$args;}
function add_submenu_page(...$args){$GLOBALS['menus'][]=$args;}
require dirname(__DIR__).'/modules/product-badges/module.php';
check(!$queries&&!$styles&&!$writes,'Module boot adds no query/write/assets');
WCRM_Product_Marks::register();
check($taxKey==='wcrm_product_mark'&&$taxonomy['show_in_menu']&&$taxonomy['capabilities']['assign_terms']==='edit_products','Existing taxonomy and native Products menu preserved');
$ajax=true;WCRM_Product_Marks::seed();check(!$terms,'No seeding on AJAX');
$ajax=false;WCRM_Product_Marks::seed();check(count($terms)===3&&$options['wcrm_seeded_v1']===1,'Default badges seeded once');
$termMeta[1]['_wcrm_bg']='#123456';WCRM_Product_Marks::seed();check($termMeta[1]['_wcrm_bg']==='#123456','Existing badge colors preserved');
$termMeta[2]['_wcrm_enabled']='0';
$terms[3]->name='優惠 <安全測試>';
$mode=$argv[1]??'policy';
if($mode==='visual'){
 $screen=(object)['post_type'=>'product','taxonomy'=>'wcrm_product_mark'];WCRM_Product_Marks::admin_assets('edit-tags.php');
 $head=true;$product=new WC_Product(7);
 echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px Arial;margin:20px;background:#f0f0f1}input{max-width:100%;box-sizing:border-box}.form-field{margin:16px 0}.form-field>label{display:block;margin-bottom:6px}#col-left{max-width:520px}.product{max-width:340px;background:#fff;padding:20px;position:relative} .woocommerce-product-gallery{height:300px;background:#dbeafe} .wcrm-choice{display:flex;gap:6px}table{width:100%}</style>';
 foreach($inlineStyles as $list)foreach($list as $css)echo '<style>'.$css.'</style>';
 echo '<body class="taxonomy-wcrm_product_mark"><div class="wrap"><h1>商品徽章</h1><div id="col-left"><div class="col-wrap">';WCRM_Product_Marks::add_fields();echo '</div></div><div class="product"><h2>商品多選</h2>';WCRM_Product_Marks::box((object)['ID'=>7]);echo '<h2>商品預覽</h2><div class="woocommerce-product-gallery"></div>';WCRM_Product_Marks::single();echo '</div></div></body>';exit;
}
WCRM_Product_Marks::frontend_assets();check(!$styles&&!$scripts&&$queries===0,'Unrelated front pages load no assets or queries');
$screen=(object)['post_type'=>'post','taxonomy'=>''];WCRM_Product_Marks::admin_assets('post.php');check(!$styles&&!$scripts,'Other admin screens unaffected');
$screen=(object)['post_type'=>'product','taxonomy'=>''];WCRM_Product_Marks::admin_assets('edit.php');check($scripts===['inline-edit-post'],'Quick edit script only on product list');
if($mode==='scripts'){$single=true;WCRM_Product_Marks::frontend_assets();echo json_encode(['admin'=>implode("\n",$inlineScripts['inline-edit-post']),'front'=>implode("\n",$inlineScripts['wcrm-single-badges'])]);exit;}
$styles=[];$scripts=[];$head=true;
$html=WCRM_Product_Marks::html(7);
check(str_contains($html,'<style')&&str_contains($html,'新上架')&&!str_contains($html,'熱賣商品')&&str_contains($html,'&lt;安全測試&gt;'),'Lazy style, enabled flags and escaped names');
check(!str_contains(WCRM_Product_Marks::html(7),'<style'),'CSS emitted once for multiple products');
check(str_contains(WCRM_Product_Marks::html(9),'新上架'),'Variation inherits parent badges');
check(WCRM_Product_Marks::html(8)===''&&WCRM_Product_Marks::html(999)==='','No badges/invalid products empty');
check(str_contains(WCRM_Product_Marks::shortcode(['product_id'=>7,'position'=>'overlay']),'wcrm-marks--overlay'),'Original shortcode and overlay option preserved');
$product=new WC_Product(7);ob_start();WCRM_Product_Marks::single();$singleHtml=ob_get_clean();check(str_contains($singleHtml,'wcrm-single-source'),'Single fallback stays above title without JS');
$filterFlags['wcrm_auto_loop_display']=false;ob_start();WCRM_Product_Marks::loop();check(ob_get_clean()==='','Original loop display filter preserved');
$single=true;WCRM_Product_Marks::frontend_assets();check($scripts===['wcrm-single-badges'],'Gallery script only for marked single products');
$assignments[7]=[];$scripts=[];WCRM_Product_Marks::frontend_assets();check(!$scripts,'No gallery script for unmarked single product');$assignments[7]=[1,3];
$before=$assignments[7];$_POST=[];WCRM_Product_Marks::save_product(7,(object)['ID'=>7]);check($assignments[7]===$before,'Absent product nonce preserves assignments');
$_POST=['wcrm_product_nonce'=>['bad'],'wcrm_marks'=>[]];WCRM_Product_Marks::save_product(7,null);check($assignments[7]===$before,'Malformed nonce preserves assignments');
$_POST=['wcrm_product_nonce'=>'valid','wcrm_marks'=>[1,3,1,999,[2]]];WCRM_Product_Marks::save_product(7,null);check($assignments[7]===[1,3],'Valid IDs only, multi-select retained');
$_POST['wcrm_marks']='bad';WCRM_Product_Marks::save_product(7,null);check($assignments[7]===[1,3],'Malformed selections do not clear valid data');
$caps=false;$_POST['wcrm_marks']=[];WCRM_Product_Marks::save_product(7,null);check($assignments[7]===[1,3],'Permissions preserve data');
$caps=true;WCRM_Product_Marks::save_product(7,null);check($assignments[7]===[],'Explicit clear-all supported');
$assignments[7]=[1];$_POST=['wcrm_quick_nonce'=>'valid','wcrm_quick_ready'=>'0'];WCRM_Product_Marks::save_quick_edit(new WC_Product(7));check($assignments[7]===[1],'Failed quick edit read must not clear data');
$_POST=['wcrm_quick_nonce'=>'valid','wcrm_quick_ready'=>'1','wcrm_quick_marks'=>[1,3]];WCRM_Product_Marks::save_quick_edit(new WC_Product(7));check($assignments[7]===[1,3],'Quick-edit multi-select persists');
unset($_POST['wcrm_quick_marks']);WCRM_Product_Marks::save_quick_edit(new WC_Product(7));check($assignments[7]===[],'Quick-edit clear-all supported');
$before=$termMeta[1];$_POST=['wcrm_term_nonce'=>'invalid','wcrm_enabled'=>'0'];WCRM_Product_Marks::save_fields(1);check($termMeta[1]===$before,'Term nonce preserves colors/state');
$_POST=['wcrm_term_nonce'=>'valid','wcrm_bg'=>'#112233','wcrm_fg'=>'invalid','wcrm_order'=>20000,'wcrm_enabled'=>'0'];WCRM_Product_Marks::save_fields(1);check($termMeta[1]['_wcrm_bg']==='#112233'&&$termMeta[1]['_wcrm_fg']==='#ffffff'&&$termMeta[1]['_wcrm_order']===9999&&$termMeta[1]['_wcrm_enabled']==='0','Colors sanitized, order bounded, disable preserved');
$columns=WCRM_Product_Marks::product_columns(['name'=>'商品','price'=>'價格']);check(array_keys($columns)===['name','wcrm_marks','price'],'Product column ordering retained');
foreach($hooks['admin_menu'] as $fn)$fn();check($menus[0][0]==='wu-toolbox-modular'&&$menus[0][4]==='edit-tags.php?taxonomy=wcrm_product_mark&post_type=product','Toolbox taxonomy link coexists with native Products entry');
echo "Badge checks passed: contracts, defaults, data preservation, permissions, quick edit, variations and scoped assets.\n";
