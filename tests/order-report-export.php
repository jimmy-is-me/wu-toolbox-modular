<?php
/** Complete source preservation, admin scope, request guards and UI fixture. */
if(PHP_SAPI!=='cli')exit;
define('ABSPATH',__DIR__.'/');define('WUTM_PATH',dirname(__DIR__).'/');define('WUTM_URL','/plugin/');define('WUTM_VERSION','fixture');
$mode=$argv[1]??'enabled';$hooks=[];$menus=[];$allowed=true;$nonce=true;$profiles=[];
function rep_check($ok,$message){if(!$ok)throw new RuntimeException($message);}
$source=str_replace("\r\n","\n",file_get_contents(WUTM_PATH.'modules/order-report-export/source.php'));
$source=str_replace("const SLUG      = 'wu-order-report-export'", "const SLUG      = 'wctre-order-export-v3'",$source);
$source=str_replace("\t\t\t\t'wu-toolbox-modular',", "\t\t\t\t'woocommerce',",$source);
$source=str_replace("\t\t\twutm_admin_page_open('訂單報表匯出', '未設定日期就是全部期間。欄位預設只顯示常用項目，其餘可從下拉選單加入。');\n\t\t\techo '<div class=\"wctre-v3\">';", "\t\t\techo '<div class=\"wrap wctre-v3\">';\n\t\t\techo '<h1>WooCommerce 中文訂單報表匯出</h1>';\n\t\t\techo '<p>未設定日期就是全部期間。欄位預設只顯示常用項目，其餘可從下拉選單加入。</p>';",$source);
$source=str_replace("\t\t\twutm_admin_page_close();\n",'',$source);
$manifest=json_decode(file_get_contents(__DIR__.'/order-report-export-source.json'),true);
rep_check(hash('sha256',rtrim($source,"\n"))===$manifest['imported_sha256'],'Every source function/JS/CSV behavior retained beyond explicit class/menu/page-wrapper changes and final newline');
function is_admin(){return $GLOBALS['mode']!=='frontend';}
function add_action($hook,$fn,$priority=10,...$args){$GLOBALS['hooks'][$hook][$priority][]=$fn;}
function remove_action($hook,$fn,$priority=10){$GLOBALS['hooks'][$hook][$priority]=array_values(array_filter($GLOBALS['hooks'][$hook][$priority]??[],static function($other)use($fn){return $other!==$fn;}));}
function current_user_can(...$args){return $GLOBALS['allowed'];}
function get_current_user_id(){return 10;}
function get_user_meta($id,$key,$single=true){return $GLOBALS['profiles'][$id][$key]??'';}
function update_user_meta(...$args){throw new RuntimeException('Read-only operation wrote a profile');}
function get_transient($key){return false;}
function delete_transient($key){}
function wp_timezone(){return new DateTimeZone('Asia/Taipei');}
function wp_timezone_string(){return 'Asia/Taipei';}
function wp_date($format){return (new DateTimeImmutable('2026-10-22',wp_timezone()))->format($format);}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower($value));}
function sanitize_text_field($value){return trim(strip_tags($value));}
function wp_unslash($value){return is_array($value)?array_map('wp_unslash',$value):stripslashes($value);}
function wp_json_encode($value,...$args){return json_encode($value,...$args);}
function esc_html($value){return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');}
function esc_attr($value){return esc_html($value);}function esc_url($value){return esc_html($value);}
function selected($value,$expected,$echo=true){$result=$value==$expected?'selected="selected"':'';if($echo)echo $result;return $result;}
function checked($value,$expected,$echo=true){$result=$value==$expected?'checked="checked"':'';if($echo)echo $result;return $result;}
function admin_url($value){return 'https://report.example.test/wp-admin/'.$value;}
function wp_create_nonce($action){return 'fixture';}
function wp_nonce_field($action,$name){echo '<input type="hidden" name="'.esc_attr($name).'" value="fixture">';}
class ReportStop extends RuntimeException{}
function wp_die($message,...$args){throw new ReportStop($message);}
function wp_send_json_error($data,...$args){throw new ReportStop($data['message']??'error');}
function check_ajax_referer(...$args){if(!$GLOBALS['nonce'])throw new ReportStop('bad nonce');return true;}
function check_admin_referer(...$args){return check_ajax_referer(...$args);}
function add_submenu_page(...$args){$GLOBALS['menus'][$args[4]]=$args;}
function wutm_admin_page_open($title,$description){echo '<main class="wutm-ui-root"><header class="wutm-ui-header"><h1>'.esc_html($title).'</h1><p>'.esc_html($description).'</p></header>';}
function wutm_admin_page_close(){echo '</main>';}
if($mode!=='no-woocommerce'){
    function wc_get_orders($args){$GLOBALS['query']=$args;return [];}
    function wc_get_order_statuses(){return ['wc-processing'=>'處理中','wc-completed'=>'已完成','wc-cancelled'=>'已取消'];}
    function wc_get_price_decimals(){return 2;}
}
if($mode==='conflict'){class WCTRE_Order_Export_V3{}}
require WUTM_PATH.'modules/order-report-export/module.php';
if($mode==='late-conflict'){
    eval('class WCTRE_Order_Export_V2 {}');foreach($hooks['admin_init'][100] as $fn)$fn();
    rep_check(empty($hooks['admin_post_wctre_v3_submit'][10])&&empty($hooks['wp_ajax_wctre_v3_preview'][10]),'Late original snippets disable duplicate request handlers');
    echo "Report module late-conflict guard passed.\n";exit;
}
if(in_array($mode,['frontend','no-woocommerce','conflict'],true)){
    rep_check(!class_exists('WUTM_Order_Report_Export_V3',false),'Blocked/foreign/frontend runtime not loaded');
    rep_check(empty($hooks['admin_post_wctre_v3_submit'])&&empty($hooks['wp_ajax_wctre_v3_scan']),'No export handlers when not loaded');echo "Report module scope passed: $mode.\n";exit;
}
foreach($hooks['admin_menu'][60] as $fn)$fn();rep_check($menus['wu-order-report-export'][0]==='wu-toolbox-modular','Setting under Toolbox');
$call=static function($method,...$args){$r=new ReflectionMethod('WUTM_Order_Report_Export_V3',$method);if(PHP_VERSION_ID<80100)$r->setAccessible(true);return $r->invoke(null,...$args);};
rep_check(count($call('definitions'))===22&&count($call('default_fields'))===11,'All source built-in/common fields');
foreach(['=HYPERLINK("bad")','+1','-cmd','@SUM(A1)',"\t=1"] as $formula)rep_check(substr($call('csv_cell',$formula,'text',false),0,1)==="'",'Formula guard is unconditional');
rep_check($call('csv_cell','0912345678','code',true)==="'0912345678",'Leading zero text guard');
rep_check($call('csv_cell','1234567890123456','code',true)==="'1234567890123456",'Long identifier guard');
rep_check($call('csv_cell','123.4','money',true)==='123.40','Money format preserved');
rep_check(!$call('valid_date','2026-02-30')&&$call('valid_date','2024-02-29'),'Strict calendar dates');
$GLOBALS['allowed']=false;foreach(['submit','ajax_scan','ajax_preview'] as $method){try{WUTM_Order_Report_Export_V3::$method();throw new LogicException('Unauthorized request accepted');}catch(ReportStop $e){}}
$GLOBALS['allowed']=true;$GLOBALS['nonce']=false;foreach(['submit','ajax_scan','ajax_preview'] as $method){try{WUTM_Order_Report_Export_V3::$method();throw new LogicException('Invalid nonce accepted');}catch(ReportStop $e){}}
$GLOBALS['nonce']=true;
if($mode==='page'){WUTM_Order_Report_Export_V3::page();exit;}
echo "Report preservation/guards passed: full V3 source, 22 fields, CSV/text/date rules, admin-only lifecycle, Toolbox route and all request boundaries.\n";
