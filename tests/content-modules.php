<?php
/** CLI regression and browser fixtures for the integrated content modules. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ABSPATH', __DIR__ . '/');
define('WUTM_URL', '/');
define('WUTM_VERSION', 'test');
$hooks = []; $scripts = []; $styles = []; $inline_scripts = []; $inline_styles = [];
$options = []; $meta = []; $user_meta = []; $queries = []; $filters = []; $shortcodes = []; $blocks = [];
$admin = ($argv[1] ?? '') !== 'frontend'; $capable = true; $nonce_valid = true; $query_throw = false;
$map_status = 'publish'; $menu = []; $submenu = []; $meta_reads = 0;
class Fixture_Response extends RuntimeException { public $data; public $status; public $success; public function __construct($data, $status, $success) { $this->data=$data; $this->status=$status; $this->success=$success; } }
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
function add_action($hook, $callback, $priority=10, ...$rest) { $GLOBALS['hooks'][$hook][$priority][] = $callback; }
function add_filter($hook, $callback, ...$rest) { $GLOBALS['filters'][$hook][] = $callback; }
function remove_filter($hook, $callback, ...$rest) { $GLOBALS['filters'][$hook] = array_values(array_filter($GLOBALS['filters'][$hook] ?? [], static fn($item) => $item !== $callback)); }
function apply_fixture_filters($hook, $value, $query) { foreach ($GLOBALS['filters'][$hook] ?? [] as $fn) $value = $fn($value, $query); return $value; }
function is_admin() { return $GLOBALS['admin']; }
function current_user_can(...$args) { return $GLOBALS['capable']; }
function check_ajax_referer(...$args) { return $GLOBALS['nonce_valid']; }
function wp_send_json_error($data, $status=200) { throw new Fixture_Response($data,$status,false); }
function wp_send_json_success($data) { throw new Fixture_Response($data,200,true); }
function wp_die($message) { throw new RuntimeException($message); }
function get_option($key, $default=false) { return $GLOBALS['options'][$key] ?? $default; }
function get_current_user_id() { return 12; }
function get_userdata($id) { return (object) ['ID'=>$id]; }
function user_can(...$args) { return true; }
function get_user_meta($id, $key, ...$rest) { return $GLOBALS['user_meta'][$id][$key] ?? ''; }
function update_user_meta($id, $key, $value) { $GLOBALS['user_meta'][$id][$key]=$value; }
function admin_url($path='') { return '/wp-admin/' . $path; }
function is_plugin_active(...$args) { return false; }
function is_plugin_active_for_network(...$args) { return false; }
function is_multisite() { return false; }
function wutm_license_is_valid() { return true; }
class WUTM_License_Manager { public static function instance() { return new self; } public function render_panel() {} }
function wp_nonce_field($action,$name) { echo '<input type="hidden" name="'.esc_attr($name).'" value="valid">'; }
function wp_create_nonce(...$args) { return 'valid'; }
function wp_verify_nonce($nonce,...$args) { return $nonce==='valid'; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim(strip_tags((string)$value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string)$value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$value)); }
function sanitize_title($value) { return (string)$value; }
function sanitize_hex_color($value) { return preg_match('/^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/i',$value) ? $value : null; }
function esc_html($value) { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_textarea($value) { return esc_html($value); }
function esc_js($value) { return addslashes($value); }
function esc_url($value) { return esc_html($value); }
function esc_url_raw($value) { return preg_match('/^(?:javascript|data):/i',(string)$value) ? '' : (string)$value; }
function absint($value) { return abs((int)$value); }
function selected($value,$expected,$echo=true) { $out=(string)$value===(string)$expected?'selected':''; if($echo)echo $out;return $out; }
function checked($value,$expected=true,$echo=true) { $out=(bool)$value===(bool)$expected?'checked':'';if($echo)echo $out;return $out; }
function disabled($value,$expected=true,$echo=true) { $out=(bool)$value===(bool)$expected?'disabled':'';if($echo)echo $out;return $out; }
function wp_json_encode($value,$flags=0) { return json_encode($value,$flags); }
function wp_parse_url($value,$component=-1) { return parse_url($value,$component); }
function get_current_screen() { return (object)['id'=>'wu-toolbox_page_wco-content-overview']; }
function wp_kses_post($value) { return strip_tags($value,'<span><b><p><strong>'); }
function shortcode_atts($defaults,$atts,...$rest) { return array_merge($defaults,array_intersect_key($atts,$defaults)); }
function get_post_type($id) { return $id===7 ? 'wumetax_map' : 'page'; }
function get_post_status($id) { return $GLOBALS['map_status']; }
function get_the_title($id) { return '台北展示中心'; }
function get_post_meta($id,$key='',$single=false) { $GLOBALS['meta_reads']++; $all=$GLOBALS['meta'][$id]??[]; if($key==='')return array_map(static fn($value)=>[$value],$all);return $all[$key]??''; }
function update_post_meta($id,$key,$value) { $GLOBALS['meta'][$id][$key]=$value; }
function add_shortcode($tag,$callback) { $GLOBALS['shortcodes'][$tag]=$callback; }
function register_post_type($type,$args) { $GLOBALS['post_type_args'][$type]=$args; }
function register_block_type($type,$args) { $GLOBALS['blocks'][$type]=$args; }
function add_meta_box(...$args) { $GLOBALS['meta_boxes'][]=$args; }
function add_submenu_page($parent,$title,$label,$cap,$slug,$callback) { $GLOBALS['submenu'][$parent][]=[$label,$cap,$slug,$title];return $parent.'_page_'.$slug; }
function wp_register_style(...$args) {}
function wp_register_script(...$args) { $GLOBALS['script_registrations'][]=$args; }
function wp_enqueue_style($handle,...$args) { $GLOBALS['styles'][]=$handle; }
function wp_enqueue_script($handle,...$args) { $GLOBALS['scripts'][]=$handle; }
function wp_add_inline_style($handle,$source) { $GLOBALS['inline_styles'][$handle][]=$source; }
function wp_add_inline_script($handle,$source,$position='after') { $GLOBALS['inline_scripts'][$handle][$position][]=$source; }
function get_posts($args) { $GLOBALS['queries'][]=$args; return [(object)['ID'=>7,'post_title'=>'台北 </script> 展示中心']]; }
function get_terms(...$args) { return [(object)['term_id'=>3,'name'=>'服飾']]; }
function is_wp_error($value) { return false; }
function get_permalink($post) { return '/?p='.(is_object($post)?$post->ID:$post); }
function get_preview_post_link($post) { return get_permalink($post).'&preview=true'; }
function get_edit_post_link($id,...$args) { return '/wp-admin/post.php?post='.$id.'&action=edit'; }
function get_the_category($id) { return $id===3 ? [] : [(object)['term_id'=>9,'name'=>'新聞'],(object)['term_id'=>2,'name'=>'公告']]; }
function get_the_post_thumbnail_url(...$args) { return '/sample.png'; }
function wp_get_post_terms(...$args) { return ['服飾']; }
function get_the_date(...$args) { return '2026-10-05'; }
function wp_count_posts(...$args) { return (object)['publish'=>21,'draft'=>1]; }
function wc_get_product_types() { return ['simple'=>'簡單商品']; }
function wc_stock_amount($value) { return $value; }
class Fixture_Product {
 public function get_stock_status(){return 'instock';} public function get_type(){return 'simple';}
 public function get_sku(){return 'WU-001';} public function get_stock_quantity(){return 12;}
 public function managing_stock(){return true;} public function get_name(){return '完整商品名稱 <安全測試>';}
 public function get_price_html(){return '<span>NT$1,200</span>';}
}
if (($argv[1] ?? '') !== 'no-wc') { class WooCommerce {} function wc_get_product($id) { return new Fixture_Product; } }
class Fixture_DB {
 public $prefix='wp_'; public $posts='wp_posts'; public $postmeta='wp_postmeta';
 public function esc_like($value){return addcslashes($value,'_%\\');}
 public function prepare($sql,...$values){foreach($values as $value)$sql=preg_replace('/%[sd]/',"'".str_replace("'","''",(string)$value)."'",$sql,1);return $sql;}
}
$wpdb=new Fixture_DB;
class WP_Query {
 public $posts=[]; public $found_posts=21; public $max_num_pages=2; private $args;
 public function __construct($args){
  $this->args=$args;$GLOBALS['queries'][]=$args;
  $GLOBALS['where_checked']=apply_fixture_filters('posts_where','WHERE 1=1',$this);
  $GLOBALS['clauses_checked']=apply_fixture_filters('posts_clauses',['join'=>'','orderby'=>'ID DESC'],$this);
  if($GLOBALS['query_throw'])throw new RuntimeException('fixture query failure');
  $items=$args['post_type']==='page' ? [[1,0,'publish','首頁'],[2,1,'draft','子頁完整標題'],[3,888,'pending','孤立頁'],[4,5,'private','循環甲'],[5,4,'future','循環乙']] : [[1,0,'publish','內容一'],[3,0,'draft','內容二']];
  if($args['post_type']==='product')$items=[[11,0,'publish','預購商品']];
  foreach($items as [$id,$parent,$status,$title])$this->posts[]=(object)['ID'=>$id,'post_parent'=>$parent,'post_status'=>$status,'post_title'=>$title,'post_date'=>'2026-10-05'];
 }
 public function get($key){return $this->args[$key]??null;}
}
function invoke_overview($method,...$args){return (new ReflectionMethod('WUTM_Content_Overview',$method))->invoke(null,...$args);}
function json_result($callback){try{$callback();throw new RuntimeException('Expected JSON response');}catch(Fixture_Response $result){return $result;}}
require dirname(__DIR__).'/modules/content-overview/module.php';
if (!$admin) {
    check(!class_exists('WUTM_Content_Overview',false)&&!$queries&&!$scripts&&!$styles,'Overview implementation excluded from front end');
    require dirname(__DIR__).'/modules/google-maps/module.php';
    wutm_google_map_register_post_type();wutm_google_map_register_block();
    check(!$queries&&!$scripts&&!$styles,'Enabled map module without a rendered map has no frontend queries or assets');
    echo "Frontend scope passed.\n";exit;
}
require dirname(__DIR__).'/modules/google-maps/module.php';
require dirname(__DIR__).'/core/module-registry.php';
require dirname(__DIR__).'/core/admin-page.php';
$meta[7]=['_wm_address'=>'台北市信義區','_wm_height'=>500,'_wm_mobile_height'=>320,'_wm_show_info'=>'1','_wm_info_title'=>'展示中心','_wm_info_label'=>'LOCATION','_wm_info_address'=>"台北市\n歡迎預約",'_wm_button_text'=>'Google Maps 導航','_wm_style'=>'dark','_wm_loading'=>'lazy'];
$mode=$argv[1]??'policy';
if($mode==='dashboard'){
 $options=['wutm_admin_menu_editor_owner_ids'=>[12],'wutm_module_google_maps'=>1,'wutm_module_admin_bar_cleaner'=>1];
 echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><div id="wpbody-content">';wutm_render_admin_page();echo '</div>';exit;
}
if($mode==='overview'||$mode==='no-wc'){
 WUTM_Content_Overview::menu();WUTM_Content_Overview::assets('wu-toolbox-modular_page_wco-content-overview');
 echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><div id="wpbody-content">';
 foreach($inline_styles as $list)foreach($list as $css)echo '<style>'.$css.'</style>';
 WUTM_Content_Overview::render();echo '</div>';
 foreach($inline_scripts as $positions)foreach($positions as $list)foreach($list as $js)echo '<script>'.$js.'</script>';
 exit;
}
if($mode==='map-admin'){echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';wutm_google_map_settings_callback((object)['ID'=>7]);exit;}
if($mode==='map'){echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';echo wutm_google_map_shortcode(['id'=>7]);echo wutm_google_map_block_render(['mapId'=>7]);exit;}
if($mode==='js'){echo invoke_overview('js');exit;}
if($mode==='data'){$_POST=['view'=>$argv[2]??'pages'];$result=json_result(['WUTM_Content_Overview','ajax_load']);echo json_encode(['success'=>$result->success,'data'=>$result->data]);exit;}

check(!$queries&&!$scripts&&!$styles,'No queries or assets merely from enabling modules');
check(!wutm_is_enabled('google-maps')&&!wutm_is_enabled('content-overview'),'New modules default off');
check(wutm_modules()['google-maps']['group']==='內容管理','Map card category');
check(wutm_modules()['content-overview']['group']==='內容總覽','Overview card category');
check(wutm_modules()['disable-wordpress-updates']['group']==='特殊工具','Updates category');
check(array_keys(wutm_grouped_modules())[1]==='內容總覽','Overview group follows backend interface');
wutm_google_map_register_post_type();wutm_google_map_register_block();wutm_google_map_add_meta_boxes();
check($post_type_args['wumetax_map']['show_in_menu']==='wu-toolbox-modular','Maps submenu parent');
check(isset($shortcodes['wumetax_map'],$blocks['wumetax/map'])&&count($meta_boxes)===2,'Original shortcode, dynamic block, both meta boxes preserved');
WUTM_Content_Overview::menu();
check($submenu['wu-toolbox-modular'][0][2]==='wco-content-overview','Overview submenu parent');
WUTM_Content_Overview::assets('dashboard');
check(!$scripts&&!$styles&&!$queries,'Overview assets excluded from other pages');
WUTM_Content_Overview::assets('wu-toolbox-modular_page_wco-content-overview');
check($scripts===['wco-v5-script']&&$styles===['wco-v5-style'],'Overview assets only on its screen');
$scripts=[];$styles=[];$inline_scripts=[];
wutm_google_map_block_editor_assets();
check($queries[0]['post_status']==='publish'&&!$queries[0]['update_post_meta_cache']&&!$queries[0]['update_post_term_cache'],'Editor map list uses lightweight published query');
check(!str_contains($inline_scripts['wumetax-map-block-editor']['before'][0],'</script>'),'Editor titles JSON safely encoded');
check(count(invoke_overview('columns'))===11,'All original product columns preserved');
$capable=false; $nonce_valid=true; $_POST=['view'=>'pages'];
$result=json_result(['WUTM_Content_Overview','ajax_load']);
check($result->status===403&&!$result->success,'Overview permission check');
$capable=true;$nonce_valid=false;
$result=json_result(['WUTM_Content_Overview','ajax_load']);
check($result->status===403,'Overview nonce check');
$nonce_valid=true;$_POST=['view'=>'invalid'];
check(json_result(['WUTM_Content_Overview','ajax_load'])->status===400,'Invalid views rejected');
$_POST=['view'=>'pages'];
$data=json_result(['WUTM_Content_Overview','ajax_load'])->data;
check($data['total']===5&&$data['tree'][0]['children'][0]['id']===2,'Hierarchy and all statuses retained');
check($queries[count($queries)-1]['no_found_rows']&&!$queries[count($queries)-1]['update_post_meta_cache'],'Tree skips unused counts and metadata');
$ids=[];$visit=function($nodes)use(&$visit,&$ids){foreach($nodes as $node){$ids[]=$node['id'];$visit($node['children']);}};$visit($data['tree']);
sort($ids);check($ids===[1,2,3,4,5],'Orphans and cycles appear once without infinite recursion');
$_POST=['view'=>'posts'];$data=json_result(['WUTM_Content_Overview','ajax_load'])->data;
check($data['total']===2&&count($data['tree'])===2,'Post categories include uncategorized');
check(in_array('公告',array_column($data['tree'],'title'),true),'Multi-category posts select first term ID');
$_POST=['view'=>'products','q'=>"12' OR 1=1 --",'sort'=>'price','order'=>'asc','category'=>'3','stock'=>'instock'];
$data=json_result(['WUTM_Content_Overview','ajax_load'])->data;
$last=$queries[count($queries)-1];
check($last['posts_per_page']===20&&$last['tax_query'][0]['include_children']&&$last['meta_query'][0]['key']==='_stock_status','Products paginated with category and stock filters');
check(count($data['rows'][0]['cells'])===11&&str_contains($data['rows'][0]['cells']['name'],'&lt;安全測試&gt;'),'Full product cells escaped');
check(str_contains($where_checked,"12'' OR")&&str_contains($where_checked,'wco_vsku'),'Prepared SKU search includes variations');
check(str_contains($clauses_checked['orderby'],'min_price ASC')&&str_contains($clauses_checked['join'],'LEFT JOIN'),'Lookup sorting preserves missing values');
check(!$filters['posts_where']&&!$filters['posts_clauses'],'Query filters removed after success');
$query_throw=true;
try{invoke_overview('product_data');}catch(RuntimeException $error){}
check(!$filters['posts_where']&&!$filters['posts_clauses'],'Query filters removed after exception');
$query_throw=false;
$_POST=['columns'=>['price','bogus',['sku'],'price']];
json_result(['WUTM_Content_Overview','ajax_columns']);
check($user_meta[12]['wco_v5_product_columns']===['price','name'],'Columns scoped per user, whitelisted and name required');
$nonce_valid=false;$_POST=['columns'=>[]];json_result(['WUTM_Content_Overview','ajax_columns']);
check($user_meta[12]['wco_v5_product_columns']===['price','name'],'Invalid nonce preserves personal columns');
$before=$meta[7];$_POST=['wumetax_map_nonce'=>['malformed']];wutm_google_map_save(7);check($meta[7]===$before,'Malformed map nonce preserves data');
$_POST=['wumetax_map_nonce'=>'invalid','wm_address'=>'bad'];wutm_google_map_save(7);check($meta[7]===$before,'Invalid map nonce preserves data');
$capable=false;$_POST=['wumetax_map_nonce'=>'valid','wm_address'=>'bad'];wutm_google_map_save(7);check($meta[7]===$before,'Map edit capability required');
$capable=true;$_POST=['wumetax_map_nonce'=>'valid','wm_address'=>['bad']];wutm_google_map_save(7);check($meta[7]===$before,'Malformed map fields rejected safely');
$_POST=['wumetax_map_nonce'=>'valid','wm_address'=>'台北','wm_zoom'=>80,'wm_width'=>'80vw','wm_max_width'=>'none','wm_alignment'=>'right','wm_full_width'=>'1','wm_height'=>'720','wm_mobile_height'=>'360','wm_style'=>'custom','wm_custom_filter'=>'grayscale(1)','wm_radius'=>'20','wm_border_width'=>'2','wm_border_color'=>'#112233','wm_shadow'=>'large','wm_show_info'=>'1','wm_info_label'=>'LOCATION','wm_info_title'=>'展示中心','wm_info_address'=>"台北\n信義區",'wm_button_text'=>'導航','wm_allow_fullscreen'=>'1','wm_loading'=>'eager','wm_embed_url'=>'<iframe src="https://www.google.com/maps?output=embed"></iframe>'];
wutm_google_map_save(7);
check(count($meta[7])===22&&$meta[7]['_wm_zoom']===20&&$meta[7]['_wm_max_width']==='none','All 22 map fields preserved, bounded and sanitized');
check($meta[7]['_wm_embed_url']==='https://www.google.com/maps?output=embed','Full iframe paste still supported');
check(wutm_google_map_sanitize_size('1px;display:none')==='100%'&&wutm_google_map_sanitize_size('12.5rem')==='12.5rem','CSS injection excluded, valid units preserved');
$meta_reads=0;$scripts=[];$styles=[];
$html=wutm_google_map_shortcode(['id'=>7,'height'=>'600','align'=>'left','map_style'=>'dark','info'=>'1']);
check($meta_reads===1&&!$scripts&&!$styles,'Map uses one metadata read and no front-end enqueues');
check(str_contains($html,'iframe')&&str_contains($html,'600px')&&str_contains($html,'wumetax-map__info')&&str_contains($html,'allowfullscreen'),'Shortcode overrides, info card and fullscreen preserved');
$second=wutm_google_map_block_render(['mapId'=>7]);
check(!str_contains($second,'<style>')&&str_contains($second,'iframe'),'Multiple maps emit shared CSS once');
$capable=false;$map_status='draft';check(wutm_google_map_render(7)==='','Unpublished map data not exposed publicly');
$capable=true;check(str_contains(wutm_google_map_render(7),'iframe'),'Editors may preview draft maps');
check(wutm_google_map_render(0)===''&&wutm_google_map_render(8)==='','Invalid map IDs rejected');
require dirname(__DIR__).'/core/module-menu.php';
$options['wutm_module_google_maps']=1;$options['wutm_module_content_overview']=1;$options['wutm_module_admin_menu_editor']=1;
$submenu['wu-toolbox-modular']=[['WU Toolbox','manage_options','wu-toolbox-modular'],['地圖管理','edit_posts','edit.php?post_type=wumetax_map'],['內容總覽','manage_options','wco-content-overview'],['後台選單編輯器','manage_options','wu-admin-menu-editor']];
foreach($hooks['admin_menu'][9999] as $callback)$callback();
$slugs=array_column($submenu['wu-toolbox-modular'],2);
check(array_search('wu-admin-menu-editor',$slugs)<array_search('wco-content-overview',$slugs)&&array_search('wco-content-overview',$slugs)<array_search('edit.php?post_type=wumetax_map',$slugs),'Grouped Toolbox submenu order includes native map CPT');
$entry=array_values(array_filter($submenu['wu-toolbox-modular'],static fn($entry)=>$entry[2]==='edit.php?post_type=wumetax_map'))[0];
check($entry[0]==='Google 地圖','Native map entry uses card label');
$styles=[];$_GET=['page'=>'wco-content-overview'];
foreach($hooks['admin_enqueue_scripts'][10] as $callback)$callback('unrelated-hook');
check($styles===['wutm-admin'],'Shared appearance supports WordPress actual parent hook and overview slug');
echo "Content modules passed: contracts, data preservation, security, trees, paginated products, query cleanup and scoped assets.\n";
