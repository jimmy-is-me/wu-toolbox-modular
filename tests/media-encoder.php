<?php
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');define('WUTM_VERSION','test');define('DB_NAME','fixture');
$hooks=[];$options=['wutm_media_encoder_settings'=>['quality'=>84,'formats'=>['jpeg','png'],'max_width'=>1920,'disabled_sizes'=>['medium','scaled']]];$styles=[];$scripts=[];$inline=[];$caps=true;$nonce=true;$files=[];$metas=[];$mimes=[];$postMeta=[];$deleted=[];$generateFail=false;$generateThrow=false;$updateFail=false;$scaled=false;$front=($argv[1]??'')==='frontend';
class WP_Error {private $message;function __construct($code,$message){$this->message=$message;}function get_error_message(){return $this->message;}}
function is_wp_error($v){return $v instanceof WP_Error;}
function check($ok,$label){if(!$ok)throw new RuntimeException($label);}
function add_action($hook,$fn,...$args){$GLOBALS['hooks'][$hook][]=$fn;}function add_filter(...$args){add_action(...$args);}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
function wp_parse_args($a,$b){return array_merge($b,$a);}
function is_admin(){return !$GLOBALS['front'];}
function current_user_can(...$args){return $GLOBALS['caps'];}
function wp_get_upload_dir(){return ['basedir'=>$GLOBALS['uploads'],'baseurl'=>'https://example.test/uploads'];}
function wp_upload_dir(){return wp_get_upload_dir();}
function wp_normalize_path($v){return str_replace('\\','/',$v);}
function trailingslashit($v){return rtrim($v,'/\\').'/';}
function wp_convert_hr_to_bytes($v){$last=strtolower(substr($v,-1));return (int)$v*(['g'=>1073741824,'m'=>1048576,'k'=>1024][$last]??1);}
function wp_raise_memory_limit($v){}
function wp_unique_filename($dir,$name){$base=pathinfo($name,PATHINFO_FILENAME);$ext=pathinfo($name,PATHINFO_EXTENSION);for($n=1;file_exists($dir.'/'.$base.'-'.$n.'.'.$ext);$n++);return $base.'-'.$n.'.'.$ext;}
function wp_delete_file($file){$GLOBALS['deleted'][]=$file;if(is_file($file))unlink($file);}
function get_attached_file($id){return $GLOBALS['files'][$id]??'';}
function update_attached_file($id,$file){$GLOBALS['files'][$id]=$file;}
function get_post_type($id){return isset($GLOBALS['files'][$id])?'attachment':'post';}
function get_post_mime_type($id){return $GLOBALS['mimes'][$id]??'';}
function wp_get_attachment_metadata($id){return $GLOBALS['metas'][$id]??false;}
function wp_update_attachment_metadata($id,$meta){$GLOBALS['metas'][$id]=$meta;}
function wp_get_image_mime($file){$size=@getimagesize($file);return $size['mime']??false;}
function wp_filesize($file){return filesize($file);}
function wp_update_post($post,$error=false){if($GLOBALS['updateFail'])return new WP_Error('update','forced update');$GLOBALS['mimes'][$post['ID']]=$post['post_mime_type'];return $post['ID'];}
function update_post_meta($id,$key,$value){$GLOBALS['postMeta'][$id][$key]=$value;}
function get_post_meta($id,$key,...$args){return $GLOBALS['postMeta'][$id][$key]??'';}
function get_the_title($id){return '商品與品牌 '.$id;}
function wp_get_attachment_image_url($id,$size){return false;}
function get_edit_post_link($id,...$args){return 'https://example.test/wp-admin/post.php?post='.$id;}
function current_time($format){return '2026-10-17 12:00:00';}
function _prime_post_caches(...$args){$GLOBALS['primed']=$args;}
function check_ajax_referer(...$args){if(!$GLOBALS['nonce'])throw new RuntimeException('nonce');}
function wp_send_json_error($data,$code=200){throw new MediaResponse(false,$data,$code);}
function wp_send_json_success($data){throw new MediaResponse(true,$data,200);}
class MediaResponse extends RuntimeException{public $success,$data,$status;function __construct($success,$data,$status){$this->success=$success;$this->data=$data;$this->status=$status;}}
function sanitize_key($v){return preg_replace('/[^a-z0-9_-]/','',strtolower($v));}
function absint($v){return abs((int)$v);}
function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}function esc_attr($v){return esc_html($v);}function esc_url($v){return esc_html($v);}
function __($v,...$a){return $v;}function esc_html__($v,...$a){return esc_html($v);}
function checked($v,$compare=true,$echo=true){$out=$v==$compare?'checked':'';if($echo)echo $out;return $out;}
function admin_url($v){return 'https://example.test/wp-admin/'.$v;}
function plugins_url($v,$file){return 'https://example.test/plugins/media-encoder/'.$v;}
function wp_nonce_field($name){echo '<input type="hidden" name="_wpnonce" value="fixture-nonce">';}
function check_admin_referer(...$args){if(!$GLOBALS['nonce'])throw new RuntimeException('nonce');}
function wp_die($message){throw new RuntimeException($message);}
function wp_safe_redirect($url){throw new RuntimeException('saved');}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function wp_create_nonce($name){return 'fixture-nonce';}
function wp_json_encode($v){return json_encode($v);}
function wp_enqueue_style($name,...$args){$GLOBALS['styles'][]=$name;}
function wp_enqueue_script($name,...$args){$GLOBALS['scripts'][]=$name;}
function wp_add_inline_script($name,$value,...$args){$GLOBALS['inline'][$name]=$value;}
function get_intermediate_image_sizes(){return ['thumbnail','medium','catalog_card'];}
function size_format($n){return number_format($n/1024,1).' KB';}
function add_submenu_page(...$args){}
class MediaDB {public $prefix='wp_',$posts='wp_posts',$last_error='',$queries=[],$locked=true,$ids=[];
 function prepare($sql,...$args){$i=0;return preg_replace_callback('/%[ds]/',function($m)use($args,&$i){$v=$args[$i++];return $m[0]==='%d'?(int)$v:"'".str_replace("'","''",$v)."'";},$sql);}
 function get_var($sql){$this->queries[]=$sql;return strpos($sql,'GET_LOCK')!==false?($this->locked?'1':'0'):'1';}
 function get_col($sql){$this->queries[]=$sql;return $this->ids;}
}
$wpdb=new MediaDB;$_wp_additional_image_sizes=['catalog_card'=>['width'=>640,'height'=>480,'crop'=>true]];
require dirname(__DIR__).'/modules/media-encoder/module.php';
check(!$styles&&!$scripts&&!$wpdb->queries,'Module boot never scans or loads assets');
if($front){check(!function_exists('wutm_media_admin_ajax'),'Admin-only code absent on frontend');echo "Media frontend isolation passed.\n";exit;}
$_GET=[];foreach($hooks['admin_enqueue_scripts'] as $fn)$fn();check(!$styles&&!$scripts,'Other admin pages have no assets');
$_GET=['page'=>'wu-media-encoder'];$caps=false;foreach($hooks['admin_enqueue_scripts'] as $fn)$fn();check(!$styles&&!$scripts,'Unauthorized assets blocked');$caps=true;
foreach($hooks['admin_enqueue_scripts'] as $fn)$fn();check($scripts===['wutm-media-admin']&&$styles===['wutm-media-admin'],'Settings assets scoped correctly');
ob_start();wutm_media_settings_page();$shell=ob_get_clean();
foreach(['webp_enabled','quality','formats[]','replace_original','resize_enabled','max_width','max_height','disabled_sizes[]'] as $field)check(strpos($shell,'name="'.$field.'"')!==false,'Original setting retained '.$field);
check(strpos($shell,'value="scaled" checked')!==false&&strpos($shell,'value="medium" checked')!==false,'Existing size choices render unchanged');
check(!$wpdb->queries,'Settings rendering never queries library');
check(wutm_media_settings()['quality']===84&&wutm_media_settings()['max_width']===1920,'Original option key and values retained');
check(wutm_media_convertible('image/png',wutm_media_settings())&&!wutm_media_convertible('image/gif',wutm_media_settings()),'Original format restrictions retained');
$_POST=['operation'=>'list'];$caps=false;try{wutm_media_admin_ajax();}catch(MediaResponse $e){check($e->status===403,'Permissions required');}$caps=true;$nonce=false;
try{wutm_media_admin_ajax();throw new RuntimeException('nonce accepted');}catch(RuntimeException $e){check($e->getMessage()==='nonce','Nonce required');}$nonce=true;
$wpdb->ids=[];try{wutm_media_admin_ajax();}catch(MediaResponse $e){check($e->success&&$e->data['rows']===[]&&!$e->data['more'],'Empty library returns complete payload');}
$query=end($wpdb->queries);check(strpos($query,'LIMIT 21')!==false&&strpos($query,'COUNT')===false&&strpos($query,'OFFSET')===false,'Bounded no-count keyset query');
check(empty($hooks['wp_ajax_nopriv_wutm_media_admin']),'No public media management endpoint');
$beforeOptions=$options;
$_POST=['quality'=>200,'formats'=>['png',['invalid']],'disabled_sizes'=>['scaled','medium','unknown',['invalid']],'max_width'=>12000,'max_height'=>0,'webp_enabled'=>1,'replace_original'=>1,'resize_enabled'=>1];
try{$hooks['admin_post_wutm_save_media_encoder'][0]();}catch(RuntimeException $e){check($e->getMessage()==='saved','Settings save routes through admin-post');}
check($options[WUTM_MEDIA_OPTION]['quality']===100&&$options[WUTM_MEDIA_OPTION]['max_width']===10000,'Settings bounds');
check($options[WUTM_MEDIA_OPTION]['disabled_sizes']===['medium','scaled']&&$options[WUTM_MEDIA_OPTION]['formats']===['png'],'Scaled toggle saved; invalid nested input excluded');
$options=$beforeOptions;
$_POST=['operation'=>'list','cursor'=>50];try{wutm_media_admin_ajax();}catch(MediaResponse $e){}check(strpos(end($wpdb->queries),'ID < 50')!==false,'ID cursor pagination');
$mode=$argv[1]??'policy';if($mode==='shell'){echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><div id="wpbody-content">'.$shell.'</div><script>'.$inline['wutm-media-admin'].'</script>';exit;}
if(!function_exists('imagewebp')){if(getenv('WUTM_REQUIRE_GD'))throw new RuntimeException('GD required for encoding regression');echo "GD fixture unavailable; real encoding checks require GD.\n";exit;}
$temp=wp_normalize_path(sys_get_temp_dir()).'/wutm-media-'.bin2hex(random_bytes(5));mkdir($temp);$uploads=$temp.'/uploads';mkdir($uploads);$created=[];
register_shutdown_function(function()use($temp){foreach([$temp.'/uploads',$temp] as $dir){foreach((array)glob($dir.'/*') as $f)if(is_file($f))unlink($f);if(is_dir($dir))rmdir($dir);}});
function fixture_image($name){$file=$GLOBALS['uploads'].'/'.$name;$img=imagecreatetruecolor(40,30);imagefill($img,0,0,imagecolorallocate($img,40,100,160));imagepng($img,$file);unset($img);return $file;}
class RealMediaEditor {
 private $file,$quality=82;function __construct($file){$this->file=$file;}
 function set_quality($q){$this->quality=$q;}
 function save($path,$mime=null){$img=imagecreatefromstring(file_get_contents($this->file));$ok=imagewebp($img,$path,$this->quality);unset($img);return $ok?['path'=>$path,'mime-type'=>'image/webp']:new WP_Error('save','save failed');}
}
function wp_get_image_editor($file){return new RealMediaEditor($file);}
function wp_generate_attachment_metadata($id,$file){
 if($GLOBALS['generateThrow'])throw new RuntimeException('forced metadata exception');
 if($GLOBALS['generateFail']){$GLOBALS['metas'][$id]=['file'=>'partial'];return [];}
 if($GLOBALS['scaled']){$target=dirname($file).'/'.pathinfo($file,PATHINFO_FILENAME).'-scaled.webp';copy($file,$target);$file=$target;}
 return ['file'=>basename($file),'width'=>40,'height'=>30,'filesize'=>filesize($file),'sizes'=>[]];
}
$files[7]=fixture_image('example.png');$mimes[7]='image/png';$metas[7]=['file'=>'example.png','width'=>40,'height'=>30,'sizes'=>['thumbnail'=>['file'=>'old-thumb.png']]];
fixture_image('old-thumb.png');$wpdb->locked=false;
check(is_wp_error(wutm_media_convert_attachment(7))&&file_exists($files[7]),'Concurrent conversion rejected safely');$wpdb->locked=true;
$generateFail=true;$original=$files[7];$oldMetadata=$metas[7];
check(is_wp_error(wutm_media_convert_attachment(7,true))&&$files[7]===$original&&$metas[7]===$oldMetadata&&$mimes[7]==='image/png'&&file_exists($original)&&file_exists($uploads.'/old-thumb.png'),'Metadata failure restores data and preserves originals');
$generateFail=false;$generateThrow=true;
check(is_wp_error(wutm_media_convert_attachment(7,true))&&$files[7]===$original&&$mimes[7]==='image/png'&&file_exists($original),'Metadata exceptions rollback safely');$generateThrow=false;
$updateFail=true;check(is_wp_error(wutm_media_convert_attachment(7))&&$files[7]===$original&&file_exists($original),'Post update failure retains original');$updateFail=false;
$result=wutm_media_convert_attachment(7,false);
check(!is_wp_error($result)&&wp_get_image_mime($files[7])==='image/webp'&&file_exists($original)&&file_exists($uploads.'/old-thumb.png'),'Real GD conversion keeps originals in batch mode');
check($result['before']>0&&$result['after']>0&&$postMeta[7]['_wutm_media_conversion']['original_kept'],'Real byte counts and tracking');
check(is_wp_error(wutm_media_convert_attachment(7)),'Already-WebP is not recompressed');
$files[8]=fixture_image('replace.png');$mimes[8]='image/png';$metas[8]=['file'=>'replace.png','sizes'=>['valid'=>['file'=>'delete-thumb.png'],'unsafe'=>['file'=>'../outside.png']]];fixture_image('delete-thumb.png');
$outside=$temp.'/outside.png';copy($files[8],$outside);$original8=$files[8];$scaled=true;$result=wutm_media_convert_attachment(8,true);$scaled=false;
check(!is_wp_error($result)&&!file_exists($original8)&&!file_exists($uploads.'/delete-thumb.png')&&file_exists($outside),'Confirmed single conversion deletes only its old local files');
check(strpos($files[8],'-scaled.webp')!==false&&$metas[8]['file']===basename($files[8]),'WordPress scaled output path preserved');
$files[9]=$outside;$mimes[9]='image/png';check(is_wp_error(wutm_media_convert_attachment(9))&&file_exists($outside),'Outside-upload path refused');
$wpdb->ids=range(30,10);try{wutm_media_admin_ajax();}catch(MediaResponse $e){check($e->data['more']&&count($e->data['rows'])===20&&$e->data['cursor']===11,'Page size and cursor bounded');}
check(count($options['wutm_media_recent'])<=12,'Recent log bounded and real');
check(strpos(end($wpdb->queries),'LIMIT 21')!==false,'List only executes bounded query');
echo "Media encoder passed: settings parity, scaled setting, permissions, bounded list, real GD output, rollback, original retention, safe deletion, scaled metadata and scope.\n";
