<?php
/** Isolated fixtures: never connects to a live site or deletes real media. */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__.'/'); define('HOUR_IN_SECONDS',3600);
$mode=$argv[1]??'policy'; $hooks=$styles=$scripts=$posts=$meta=$options=$deleted=$transients=[];
$capable=true;$nonce=true;$user=1;$deny=[];$refs=[];
function is_admin(){return ($GLOBALS['mode']!=='frontend');}
function add_action($h,$cb){$GLOBALS['hooks'][$h][]=$cb;}
function current_user_can($c,$id=0){return $GLOBALS['capable']&&!in_array($id,$GLOBALS['deny'],true);}
function get_current_user_id(){return $GLOBALS['user'];}
function add_submenu_page(...$a){$GLOBALS['menu']=$a;return 'toolbox_page_'.$a[4];}
function admin_url($s){return 'https://example.test/wp-admin/'.$s;}
function add_query_arg($a,$url){return $url.(strpos($url,'?')===false?'?':'&').http_build_query($a);}
function get_transient($k){return $GLOBALS['transients'][$k]??false;}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}
function get_theme_mod($k){return '';}
function wp_unslash($v){return is_array($v)?array_map('wp_unslash',$v):stripslashes($v);}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
function absint($v){return abs((int)$v);}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function esc_attr($v){return esc_html($v);}function esc_url($v){return esc_html($v);}
function wp_basename($v){return basename($v);}function wp_normalize_path($v){return str_replace('\\','/',$v);}
function _prime_post_caches(...$a){}
function get_post($id){return $GLOBALS['posts'][$id]??null;}
function get_post_meta($id,$k,$single=true){return $GLOBALS['meta'][$id][$k]??'';}
function wp_get_attachment_metadata($id){return $GLOBALS['meta'][$id]['metadata']??[];}
function wp_get_attachment_url($id){return 'https://example.test/uploads/'.get_post_meta($id,'_wp_attached_file');}
function get_attached_file($id,$raw=false){return $GLOBALS['meta'][$id]['path']??'';}
function wp_upload_dir(...$a){return ['basedir'=>__DIR__,'error'=>false];}
function wp_delete_attachment($id,$force){$GLOBALS['deleted'][]=$id;unset($GLOBALS['posts'][$id]);return true;}
class JsonResult extends RuntimeException {public $data,$success,$status;function __construct($d,$s,$status){$this->data=$d;$this->success=$s;$this->status=$status;}}
function wp_send_json_error($d,$status=200){throw new JsonResult($d,false,$status);}
function wp_send_json_success($d){throw new JsonResult($d,true,200);}
function check_ajax_referer(...$a){if(!$GLOBALS['nonce'])wp_send_json_error(['message'=>'nonce'],403);}
function wp_register_style(...$a){}function wp_register_script(...$a){}
function wp_enqueue_style($h){$GLOBALS['styles'][]=$h;}function wp_enqueue_script($h){$GLOBALS['scripts'][]=$h;}
function wp_add_inline_style($h,$s){$GLOBALS['css']=$s;}function wp_add_inline_script($h,$s){$GLOBALS['js']=$s;}
function wp_json_encode($v){return json_encode($v);}function wp_create_nonce($v){return 'fixture';}
function wp_nonce_url($url,$action){return $url.'&_wpnonce=fixture';}function wp_nonce_field($action){echo '<input type="hidden" name="_wpnonce" value="fixture">';}
function selected($a,$b){if($a===$b)echo 'selected';}function checked($a){if($a)echo 'checked';}
function number_format_i18n($v){return number_format($v);}function size_format($v){return $v.' B';}
function get_edit_post_link($id){return '#edit-'.$id;}
function wp_get_attachment_image($id,...$a){return '<img src="data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22200%22 height=%22120%22%3E%3Crect width=%22200%22 height=%22120%22 fill=%22%232563eb%22/%3E%3C/svg%3E" alt="fixture">';}
class CleanerDB {
 public $posts='posts',$postmeta='postmeta',$last_error='',$queries=[],$fail=false;
 function esc_like($v){return addcslashes($v,'_%');}
 function prepare($sql,...$values){$values=count($values)===1&&is_array($values[0])?$values[0]:$values;foreach($values as $v)$sql=preg_replace_callback('/%[ds]/',function($m)use($v){return $m[0]==='%d'?(string)(int)$v:"'".addslashes($v)."'";},$sql,1);return $sql;}
 function track($sql){$this->queries[]=$sql;$this->last_error=$this->fail?'fixture failure':'';}
 function get_results($sql){$this->track($sql);if(strpos($sql,'GROUP BY')!==false)return [(object)['y'=>2026,'m'=>10,'cnt'=>3]];return [];}
 function get_col($sql){$this->track($sql);if(strpos($sql,"meta_key = '_thumbnail_id'")!==false)return $GLOBALS['refs'];return array_keys($GLOBALS['posts']);}
 function get_var($sql){$this->track($sql);return null;}
}
$wpdb=new CleanerDB;
require dirname(__DIR__).'/modules/media-safe-cleaner/module.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
if($mode==='frontend'){check(!$hooks,'No frontend hooks or assets');echo "Frontend isolation passed\n";exit;}
function call_private($method,...$args){$r=new ReflectionMethod('WUTM_Media_Safe_Cleaner',$method);if(PHP_VERSION_ID<80100)$r->setAccessible(true);return $r->invoke(null,...$args);}
function fixture(){
 $GLOBALS['deleted']=[];$GLOBALS['posts']=[];$GLOBALS['meta']=[];$GLOBALS['deny']=[];$GLOBALS['refs']=[];$GLOBALS['options']=[];$GLOBALS['capable']=$GLOBALS['nonce']=true;$GLOBALS['user']=1;$GLOBALS['wpdb']->fail=false;
 foreach(range(1,3) as $id){$GLOBALS['posts'][$id]=(object)['ID'=>$id,'post_type'=>'attachment','post_mime_type'=>'image/png','post_status'=>'inherit','post_parent'=>0,'post_date'=>'2026-10-01 12:00:00','post_title'=>'Image '.$id,'post_name'=>'image-'.$id];$GLOBALS['meta'][$id]=['_wp_attached_file'=>'fixture-'.$id.'.png','path'=>__FILE__,'metadata'=>['width'=>200,'height'=>120,'filesize'=>123]];}
 $GLOBALS['transients']=['wutm_mc_preview_1'=>['token'=>'fixture-token','ids'=>[1,2,3],'filters'=>['from'=>'2026-10-01','before'=>'2026-11-01','keyword'=>'','type'=>'all','protect_parent'=>true],'created_at'=>time()]];
 $_POST=['token'=>'fixture-token','confirmation'=>'永久刪除','mode'=>'selected','ids'=>'[1]'];
}
function deletion(){try{WUTM_Media_Safe_Cleaner::delete_images();throw new RuntimeException('Expected JSON');}catch(JsonResult $r){return $r;}}
fixture();WUTM_Media_Safe_Cleaner::menu();
check($menu[0]==='wu-toolbox-modular'&&$menu[4]==='wu-media-safe-cleaner','Toolbox submenu, not media menu');
WUTM_Media_Safe_Cleaner::assets('dashboard');check(!$styles&&!$scripts,'Scoped assets');
WUTM_Media_Safe_Cleaner::assets('toolbox_page_wu-media-safe-cleaner');check(count($styles)===1&&count($scripts)===1,'Own hook loads assets');
if($mode==='js'){echo $js;exit;}
if($mode==='shell'||$mode==='empty'){
 $_GET=$mode==='shell'?['token'=>'fixture-token']:[];
 echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body><main id="wpbody-content">';WUTM_Media_Safe_Cleaner::render();echo '</main><style>body{margin:0}img{max-width:100%} .button{display:inline-block;padding:8px;border:1px solid #ccc}input,select{max-width:100%} '.call_private('get_inline_css').'</style></body>';exit;
}
$methods=['boot','can_access','transient_key','admin_link','read_preview','menu','assets','check_date','form_filters','types','type_mimes','get_folder_tree','collect_ids','save_snapshot_and_redirect','create_preview','quick_folder','matches_filters','batch_reference_reasons','delete_images','csv_text','download_csv','render','get_inline_css','get_inline_js'];
foreach($methods as $m)check(method_exists('WUTM_Media_Safe_Cleaner',$m),'Reference feature method retained '.$m);
check(count(call_private('types'))===7,'All image formats');
check(strpos(call_private('admin_link'),'admin.php?page=wu-media-safe-cleaner')!==false,'Redirect stays under Toolbox');
foreach(['2026-02-30','bad'] as $date){try{call_private('check_date',$date);throw new LogicException('Invalid date accepted');}catch(RuntimeException $e){check(!($e instanceof LogicException),'Date rejected');}}
try{call_private('form_filters',['type'=>'all']);throw new LogicException('Whole site allowed');}catch(RuntimeException $e){check(!($e instanceof LogicException),'Unfiltered whole site rejected');}
$f=call_private('form_filters',['from'=>'2026-10-01','before'=>'2026-11-01','type'=>'webp','protect_parent'=>1]);check($f['protect_refs']&&$f['protect_parent'],'Protection retained');
call_private('collect_ids',$f);$sql=end($wpdb->queries);check(strpos($sql,'LIMIT 15001')!==false&&strpos($sql,"image/webp")!==false&&strpos($sql,'p.post_parent = 0')!==false,'Bounded prepared filtered query');
check(call_private('csv_text','=CMD()')==="'=CMD()"&&call_private('csv_text','normal')==='normal','CSV injection protection');
fixture();$capable=false;check(deletion()->status===403&&!$deleted,'No management permission');
fixture();$nonce=false;check(deletion()->status===403&&!$deleted,'Nonce required');
fixture();$user=2;check(!deletion()->success&&!$deleted,'Snapshot bound to user');
fixture();$_POST['token']='wrong';check(!deletion()->success&&!$deleted,'Token required');
fixture();$_POST['confirmation']='yes';check(!deletion()->success&&!$deleted,'Exact destructive confirmation');
fixture();$_POST['ids']='[999]';check(!deletion()->success&&!$deleted,'Cannot delete outside preview');
fixture();$_POST['ids']=json_encode(range(1,21));check(!deletion()->success&&!$deleted,'20-item batch ceiling');
fixture();$deny=[1];check(deletion()->data['skipped']===1&&!$deleted,'Per-image deletion capability');
fixture();$posts[1]->post_parent=123;check(deletion()->data['skipped']===1&&!$deleted,'Filters rechecked immediately before delete');
fixture();$options['site_icon']=1;check(deletion()->data['skipped']===1&&!$deleted,'Site icon protected');
fixture();$refs=[1];check(deletion()->data['skipped']===1&&!$deleted,'Featured image protected');
fixture();$wpdb->fail=true;check(deletion()->status===503&&!$deleted,'DB read failure stops deletion');
fixture();$meta[1]['path']=dirname(__DIR__).'/wu-toolbox-modular.php';check(deletion()->data['skipped']===1&&!$deleted,'Outside uploads root protected');
fixture();$meta[1]['metadata']['sizes']=['small'=>['file'=>'../outside.png']];check(deletion()->data['skipped']===1&&!$deleted,'Thumbnail traversal protected');
fixture();$meta[1]['_wp_attachment_backup_sizes']=['full-orig'=>['file'=>'../outside.png']];check(deletion()->data['skipped']===1&&!$deleted,'Backup traversal protected');
fixture();$r=deletion();check($r->success&&$r->data['deleted']===1&&$deleted===[1],'Eligible image uses native deletion');
fixture();$_POST['mode']='all';$_POST['offset']=0;$r=deletion();check($r->data['processed']===3&&$deleted===[1,2,3],'All mode bounded snapshot batch');
echo "Media safe cleaner preservation, permissions, snapshot, SQL, path safety and deletion tests passed\n";
