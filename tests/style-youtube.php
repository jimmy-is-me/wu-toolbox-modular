<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__.'/'); define('WUTM_URL','/'); define('WUTM_VERSION','test');
$options=[];$hooks=[];$styles=[];$inline=[];$scripts=[];$events=[];$admin=false;$capable=true;$bar_visible=false;
function add_action($h,$cb,...$args){$GLOBALS['hooks'][$h][]=$cb;} function add_filter(...$a){add_action(...$a);}
function did_action(...$a){return 0;}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;} function update_option($k,$v,...$a){$GLOBALS['options'][$k]=$v;} function delete_option($k){unset($GLOBALS['options'][$k]);}
function wp_parse_args($a,$b){return array_merge($b,$a);} function current_user_can(...$a){return $GLOBALS['capable'];}
function is_admin(){return $GLOBALS['admin'];} function is_feed(){return false;} function is_admin_bar_showing(){return $GLOBALS['bar_visible'];}
function wp_register_style(...$a){} function wp_enqueue_style(...$a){$GLOBALS['styles'][]=$a;} function wp_add_inline_style($h,$s){$GLOBALS['inline'][$h]=$s;}
function wp_enqueue_script(...$a){$GLOBALS['scripts'][]=$a;} function wp_print_styles(...$a){}
function wp_next_scheduled($h){return $GLOBALS['events'][$h]??false;} function wp_schedule_single_event($t,$h){$GLOBALS['events'][$h]=$t;} function wp_cache_delete(...$a){}
function register_setting(...$a){} function settings_errors(){} function settings_fields($g){echo '<input type="hidden" name="option_page" value="'.$g.'"><input type="hidden" name="_wpnonce" value="fixture">';}
function submit_button($s){echo '<button type="submit">'.esc_html($s).'</button>';}
function sanitize_text_field($v){return is_scalar($v)?trim(strip_tags($v)):'';} function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function sanitize_hex_color($s){return preg_match('/^#[0-9a-f]{6}$/i',$s)?$s:'';} function esc_url_raw($s){return preg_match('#^https?://#',$s)?$s:'';}
function esc_attr($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');} function esc_html($s){return esc_attr($s);} function esc_url($s){return esc_attr($s);}
function checked($v,$e=true,$echo=true){$s=$v==$e?'checked':'';if($echo)echo $s;return $s;}
function home_url($s){return 'https://example.test'.$s;} function admin_url($s){return '/wp-admin/'.$s;} function get_bloginfo($s){return 'Demo Shop';}
function wp_date($s){return '2026年10月19日';} function wp_json_encode($s){return json_encode($s);} function wp_parse_url($s,$part=-1){return parse_url($s,$part);}
function wp_verify_nonce($s,$action){return $s==='fixture';} function wp_strip_all_tags($s){return strip_tags($s);} function apply_filters($h,$v){return $v;}
function add_submenu_page(...$a){$GLOBALS['submenu_args']=$a;}
function add_query_arg($a,$url){$parts=parse_url($url);parse_str($parts['query']??'',$q);$q=array_merge($q,$a);return ($parts['scheme']??'https').'://'.$parts['host'].($parts['path']??'').'?'.http_build_query($q);}
class FixtureDB {public $postmeta='fixture_meta';public $rows=[];public $queries=[];function esc_like($s){return addcslashes($s,'_%');}function prepare($s,...$a){return vsprintf(str_replace(['%d','%s'],['%d',"'%s'"],$s),$a);}function query($s){$this->queries[]=$s;return count($this->rows);}function get_results($s){$this->queries[]=$s;return $this->rows;}}
$wpdb=new FixtureDB;
require dirname(__DIR__).'/modules/admin-bar-cleaner/admin-style.php';
require dirname(__DIR__).'/modules/clean-youtube-player/module.php';
function theme($key){$GLOBALS['options']['np_admin_opts']=['schema'=>1,'theme'=>$key,'load_fonts'=>0];$p=new ReflectionProperty('WUTM_Admin_Style','o');if(PHP_VERSION_ID<80100)$p->setAccessible(true);$p->setValue(null,null);}
$mode=$argv[1]??'policy';
if($mode==='loader'){echo wutm_cyp_loader_js();exit;}
if($mode==='style'){
 $admin=true;theme($argv[2]??'native');$_GET=['page'=>'wu-admin-bar-cleaner','tab'=>'appearance'];WUTM_Admin_Style::admin_assets('fixture');
 echo '<!doctype html><html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="'.WUTM_Admin_Style::body_class('wutm-admin-screen').'"><main id="wpbody-content">';WUTM_Admin_Style::settings_page();echo '</main><style>'.implode("\n",$inline).'</style></body></html>';exit;
}
if($mode==='youtube'){echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body class="wutm-admin-screen"><main id="wpbody-content">';wutm_cyp_options_page_html();echo '</main></body>';exit;}
if($mode==='facade'){wutm_cyp_enqueue_inline_css();echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.implode("\n",$inline).'</style>'.wutm_cyp_filter_youtube_embed('<iframe src="https://www.youtube.com/embed/abcdefghijk" title="Test"></iframe>','https://youtu.be/abcdefghijk',[],1);exit;}
function check($v,$message){if(!$v)throw new RuntimeException($message);}
WUTM_Admin_Style::admin_assets('dashboard');WUTM_Admin_Style::front_assets();WUTM_Admin_Style::login_assets();check(!$styles,'Native default loads no styling or fonts');
check(WUTM_Admin_Style::footer('Original')==='Original','New feature preserves existing footer before configured');
foreach(['native','newsprint','botanical','flat','bauhaus'] as $key){theme($key);$s=WUTM_Admin_Style::sanitize(['theme'=>$key,'accent'=>'#123456']);check($s['theme']===$key,'Theme preserved '.$key);check(count($s)===17,'All style options retained');}
check(WUTM_Admin_Style::sanitize(['theme'=>[],'accent'=>[]])['theme']==='native','Malformed style values safe');
theme('flat');$styles=[];WUTM_Admin_Style::front_assets();check(!$styles,'Anonymous frontend has no style/font payload');
$options['wu_custom_admin_footer_text']='Existing';check(WUTM_Admin_Style::footer('Existing')==='Existing','Existing cleaner footer takes precedence');unset($options['wu_custom_admin_footer_text']);
require dirname(__DIR__).'/modules/admin-bar-cleaner/module.php';
$_GET=['tab'=>'appearance'];ob_start();(new WU_Admin_Bar_Cleaner)->admin_page();$appearance=ob_get_clean();check(substr_count($appearance,'<form')===1&&strpos($appearance,'介面與權限')!==false,'Style integrated in original cleaner page without nested forms');$_GET=[];
wutm_cyp_add_admin_menu();check($submenu_args[0]==='wu-toolbox-modular'&&is_callable($submenu_args[5]),'Callable Toolbox submenu');
$clean=wutm_cyp_sanitize_options([]);check(count($clean)===11&&!$clean['enable']&&$clean['brand_color']==='#000000','All unposted checkboxes clear');check(!$wpdb->queries,'Sanitize does not delete database cache');
$iframe='<iframe src="https://www.youtube.com/embed/abcdefghijk?start=25" title="Test"></iframe>';
check(strpos(wutm_cyp_filter_youtube_embed($iframe,'https://www.youtube.com/watch?v=abcdefghijk&t=25',[],1),'start=25')!==false,'Start retained in iframe fallback');
check(strpos(wutm_cyp_filter_youtube_embed($iframe,'https://www.youtube.com/watch?v=abcdefghijk&list=PL_test',[],1),'<iframe')!==false,'Playlist iframe fallback');
$plain='<iframe src="https://www.youtube.com/embed/abcdefghijk" title="Safe &amp; name"></iframe>';
check(wutm_cyp_filter_youtube_embed($plain,'https://youtube.com.evil.test/watch?v=abcdefghijk',[],1)===$plain,'Strict provider host');
$admin=true;check(wutm_cyp_filter_youtube_embed($plain,'https://youtu.be/abcdefghijk',[],1)===$plain,'Editor embeds unchanged');$admin=false;
$facade=wutm_cyp_filter_youtube_embed($plain,'https://youtu.be/abcdefghijk',[],1);check(strpos($facade,'cyp-facade')!==false&&strpos($facade,'<noscript>')!==false,'Facade and no-JS fallback');
$options['cyp_options']=['enable'=>0];check(wutm_cyp_filter_youtube_embed($plain,'https://youtu.be/abcdefghijk',[],1)===$plain,'Disabled output unchanged');unset($options['cyp_options']);
wutm_cyp_queue_cleanup();check(isset($events['wutm_cyp_cleanup']),'Cache cleanup scheduled');
$wpdb->rows=[(object)['meta_id'=>5,'post_id'=>1],(object)['meta_id'=>6,'post_id'=>2]];wutm_cyp_cleanup_batch();check(strpos($wpdb->queries[0],'LIMIT 100')!==false,'Bounded cleanup');check(strpos($wpdb->queries[1],'WHERE meta_id IN (5,6)')!==false,'Only selected cache rows deleted');
echo "Style and YouTube policy passed: five themes, fields, native/visitor isolation, old footer precedence, callable submenu, strict embeds, fallbacks and bounded cache cleanup.\n";
