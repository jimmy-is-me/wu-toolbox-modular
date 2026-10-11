<?php
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH',__DIR__.'/');define('WUTM_VERSION','test');define('WP_CONTENT_DIR',__DIR__);define('WEEK_IN_SECONDS',604800);define('DAY_IN_SECONDS',86400);
$options=[];$hooks=[];$caps=true;$nonce=true;$scheduled=[];$assets=[];
function check($ok,$message){if(!$ok)throw new RuntimeException($message);}
function add_action($key,$fn,...$args){$GLOBALS['hooks'][$key][]=$fn;}
function add_filter(...$args){add_action(...$args);}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
function get_transient($key){return 1;}
function current_time($format){return (new DateTimeImmutable('2026-10-12 10:30:00'))->format($format==='mysql'?'Y-m-d H:i:s':$format);}
function wp_timezone(){return new DateTimeZone('Asia/Taipei');}
function wp_check_invalid_utf8($s,$strip){return iconv('UTF-8','UTF-8//IGNORE',$s);}
function wp_unslash($s){return $s;}
function sanitize_text_field($s){return trim(strip_tags($s));}
function sanitize_key($s){return preg_replace('/[^a-z0-9_]/','',$s);}
function is_email($s){return filter_var($s,FILTER_VALIDATE_EMAIL);}
function sanitize_email($s){return filter_var($s,FILTER_SANITIZE_EMAIL);}
function wpautop($s){return '<p>'.$s.'</p>';}
function get_bloginfo(...$args){return 'Site';}
function is_wp_error($s){return $s instanceof WP_Error;}
function wp_remote_post($url,$args){$GLOBALS['remote'][]=[$url,$args];return ['code'=>200,'body'=>'{"id":"provider-123"}'];}
function wp_remote_retrieve_body($s){return $s['body'];}
function wp_remote_retrieve_response_code($s){return $s['code'];}
class WP_Error{function get_error_message(){return 'Failure';}function get_error_data(){return ['to'=>'user@example.test','subject'=>'failed mail','message'=>'FAILED BODY'];}}
function absint($s){return abs((int)$s);}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);}
function selected($a,$b){if($a===$b)echo 'selected';}
function wp_kses_post($s){return preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i','',$s);}
function wp_next_scheduled($key){return $GLOBALS['scheduled'][$key]??false;}
function wp_schedule_single_event($time,$key){$GLOBALS['scheduled'][$key]=$time;}
function current_user_can(...$args){return $GLOBALS['caps'];}
function check_ajax_referer(...$args){if(!$GLOBALS['nonce'])throw new RuntimeException('nonce');}
function nocache_headers(){}
class JsonResult extends RuntimeException{public $result;public function __construct($success,$data,$status=200){$this->result=[$success,$data,$status];}}
function wp_send_json_error($data,$status=200){throw new JsonResult(false,$data,$status);}
function wp_send_json_success($data){throw new JsonResult(true,$data);}
function admin_url($path){return 'https://example.test/wp-admin/'.$path;}
function add_query_arg($args,$url){return $url.'?'.http_build_query($args);}
function plugins_url($path,...$args){return '/'.$path;}
function add_submenu_page($parent,$title,$label,$cap,$slug,$callback){
    $GLOBALS['email_menu']=[$parent,$slug,$callback];
    // WP uses the sanitized parent title ("WU Toolbox"), not "wu-toolbox-modular".
    return 'wu-toolbox_page_'.$slug;
}
function wp_enqueue_style(...$args){$GLOBALS['assets'][]=$args;}
function wp_enqueue_script(...$args){$GLOBALS['assets'][]=$args;}
function wp_localize_script($handle,$name,$data){$GLOBALS['localized'][$name]=$data;}
function wp_create_nonce(...$args){return 'valid';}
class FakeEmailDB{
 public $prefix='wp_',$queries=[],$inserted=[],$deleteResult=0,$detail=null,$insertResult=1;
 function prepare($sql,...$args){$i=0;return preg_replace_callback('/%[sd]/',function($m)use(&$i,$args){$v=$args[$i++];return $m[0]==='%d'?(string)(int)$v:"'".str_replace("'","''",$v)."'";},$sql);}
 function esc_like($s){return addcslashes($s,'_%\\');}
 function insert($table,$data,...$args){$this->inserted[]=[$table,$data];return $this->insertResult;}
 function query($sql){$this->queries[]=$sql;return strpos($sql,'DELETE FROM wp_wu_email_logs')===0?$this->deleteResult:1;}
 function get_row($sql){$this->queries[]=$sql;if(strpos($sql,'WHERE id=')!==false)return $this->detail;
 if(strpos($sql,'wu_email_daily')!==false)return (object)['today_count'=>5,'month_count'=>20];
 return (object)['total'=>27,'sent'=>20,'failed'=>5,'blocked'=>2];}
 function get_results($sql){$this->queries[]=$sql;
 if(strpos($sql,'GROUP BY DATE')!==false)return [(object)['day'=>'2026-10-12','sent'=>20,'failed'=>5,'blocked'=>2]];
 if(strpos($sql,'GROUP BY send_method')!==false)return [(object)['send_method'=>'smtp','amount'=>27]];
 if(strpos($sql,'today_sent')!==false)return [(object)['today_sent'=>5,'today_blocked'=>0,'month_sent'=>20,'month_blocked'=>2,'total'=>27]];
 return [(object)['id'=>1,'to_email'=>'user@example.test','subject'=>'<img src=x onerror=alert(1)> 訂單通知','status'=>'sent','error_message'=>'交件成功','send_method'=>'smtp','sent_time'=>'2026-10-12 10:30:00']];}
}
$wpdb=new FakeEmailDB;
require dirname(__DIR__).'/modules/email-tracking/module.php';
check(!$wpdb->queries&&!$assets,'Module boot does no DB work or asset load');
check(wu_email_retention_days()===90&&wu_email_retention_days(0)===1&&wu_email_retention_days(900)===365,'Retention default and bounds');
$fields=wu_email_content_fields(['message'=>'<p>Hello</p>']);check($fields['message']==='<p>Hello</p>'&&$fields['content_type']==='text/html','HTML content captured');
$fields=wu_email_content_fields(['message'=>str_repeat('A',262150)]);check(strlen($fields['message'])===262144&&$fields['content_truncated']===1,'Content size bounded');
$options['wu_email_log_content']=0;check(wu_email_content_fields(['message'=>'PRIVATE'])['message']===null,'Privacy opt-out stores no body');$options['wu_email_log_content']=1;
wu_email_log_result(['to'=>['a@test.test','b@test.test'],'subject'=>'subject','message'=>'PRIVATE'],'sent','ok','smtp');
check($wpdb->inserted[0][1]['message']==='PRIVATE'&&$wpdb->inserted[0][1]['to_email']==='a@test.test, b@test.test','Existing multi-recipient and body logging');
check(strpos(end($wpdb->queries),'ON DUPLICATE KEY UPDATE amount=amount+1')!==false,'Daily aggregate increment');
$before=count($wpdb->queries);$wpdb->insertResult=false;wu_email_log_result([],'failed');check(count($wpdb->queries)===$before,'Failed log insert does not invent daily count');$wpdb->insertResult=1;
check(wu_intercept_email(true,[])===true,'Earlier mail transport short-circuit respected');
$options['wu_email_tracker_db_version']=WU_EMAIL_TRACKER_DB_VERSION;
$options['wu_email_send_method']='resend';$options['wu_email_resend_api_key']='fake-key';$options['wu_email_resend_from_email']='from@example.test';
check(wu_intercept_email(null,['to'=>'user@example.test','subject'=>'API mail','message'=>'API BODY'])===true,'API transport succeeds through normal interceptor');
check(end($wpdb->inserted)[1]['message']==='API BODY'&&end($wpdb->inserted)[1]['send_method']==='resend_api','API mail captures content and service');
$options['wu_email_daily_limit']=5;
check(wu_intercept_email(null,['to'=>'user@example.test','subject'=>'quota mail','message'=>'BLOCKED BODY'])===false,'Daily quota is still enforced');
check(end($wpdb->inserted)[1]['status']==='blocked'&&end($wpdb->inserted)[1]['message']==='BLOCKED BODY','Blocked messages have content and status');
$options['wu_email_daily_limit']=20;$options['wu_email_send_method']='smtp';
foreach($hooks['wp_mail_failed'] as $fn)$fn(new WP_Error);
check(end($wpdb->inserted)[1]['status']==='failed'&&end($wpdb->inserted)[1]['message']==='FAILED BODY','Failed SMTP events capture content');
foreach($hooks['wp_mail_succeeded'] as $fn)$fn(['to'=>'user@example.test','subject'=>'SMTP','message'=>'SMTP BODY']);
check(end($wpdb->inserted)[1]['status']==='sent'&&end($wpdb->inserted)[1]['message']==='SMTP BODY','Successful SMTP events capture content');
$f=wu_email_log_filters(['email_start'=>'2020-01-01','email_end'=>'2026-10-12','email_status'=>'bad','email_search'=>"%' OR 1=1",'email_page'=>999999]);
check($f['start']==='2025-10-13'&&$f['status']===''&&$f['page']===10000,'Bounded date range, status and pagination');
$bad=wu_email_log_filters(['email_start'=>'2026-02-31','email_end'=>[]]);check($bad['start']==='2026-09-13'&&$bad['end']==='2026-10-12','Invalid dates fallback');
$where=wu_email_log_where($f);check(strpos($where,'2026-10-13 00:00:00')!==false&&strpos($where,"'' OR")!==false,'Exclusive inclusive-date endpoint and parameterized search');
$options['wu_email_retention_days']=14;$wpdb->deleteResult=1000;wu_email_cleanup();
check(isset($scheduled['wu_email_cleanup_batch']),'Cleanup continues via bounded scheduled batches');
check(strpos($wpdb->queries[count($wpdb->queries)-2],"2026-09-28 10:30:00")!==false&&strpos($wpdb->queries[count($wpdb->queries)-2],'LIMIT 1000')!==false,'Configured retention date and cleanup batch limit');
check(wu_email_quota_counts()->month_count===20,'Quota reads independent daily aggregates');
$row=(object)['message'=>'<p>PRIVATE BODY</p><script>window.parent.pwned=true</script><img src="https://tracker.test/pixel">','content_type'=>'text/html','content_truncated'=>0,'subject'=>'<b>Unsafe title</b>','to_email'=>'user@test.test','id'=>1,'status'=>'sent','error_message'=>'ok','send_method'=>'smtp','sent_time'=>'2026-10-12'];
$doc=wu_email_preview_document($row);check(strpos($doc,'default-src &#39;none&#39;')!==false&&strpos($doc,'<script>')===false,'CSP and sanitizer in preview');
$_POST=['id'=>1];$caps=false;try{wu_email_log_detail();}catch(JsonResult $e){check($e->result[2]===403,'Details require administrator');}
$caps=true;$nonce=false;try{wu_email_log_detail();check(false,'Nonce required');}catch(RuntimeException $e){check($e->getMessage()==='nonce','Nonce rejection');}$nonce=true;
$wpdb->detail=$row;try{wu_email_log_detail();}catch(JsonResult $e){$detail=$e->result;check($detail[0]&&strpos($detail[1]['preview'],'PRIVATE BODY')!==false,'Authorized detail on demand');}
foreach($hooks['admin_enqueue_scripts'] as $fn)$fn('post.php');check(!$assets,'Other admin screens have no assets');
foreach($hooks['admin_menu'] as $fn)$fn();
check($GLOBALS['wu_email_tracking_page_hook']==='wu-toolbox_page_wu-email-tracking','Actual menu registration captures parent-title hook');
foreach(['wu-toolbox_page_wu-email-tracking','renamed-toolbox_page_wu-email-tracking','wu-toolbox-modular_page_wu-email-tracking'] as $hook){
    $assets=[];foreach($hooks['admin_enqueue_scripts'] as $fn)$fn($hook);check(count($assets)===2,'Assets load for actual and renamed parent: '.$hook);
}
$GLOBALS['wu_email_tracking_page_hook']='custom_registered_mail_screen';$assets=[];
foreach($hooks['admin_enqueue_scripts'] as $fn)$fn('custom_registered_mail_screen');check(count($assets)===2,'Registered hook works without assumed suffix');
$caps=false;$assets=[];foreach($hooks['admin_enqueue_scripts'] as $fn)$fn('wu-toolbox_page_wu-email-tracking');check(!$assets,'Non-admin never loads mail assets');$caps=true;
$assets=[];foreach($hooks['admin_enqueue_scripts'] as $fn)$fn('wu-toolbox_page_wu-email-other');check(!$assets,'Other Toolbox pages remain isolated');
foreach($hooks['admin_enqueue_scripts'] as $fn)$fn('wu-toolbox_page_wu-email-tracking');
$wpdb->queries=[];$_GET=[];ob_start();wu_email_render_logs();$html=ob_get_clean();
check(count($wpdb->queries)===4,'Bounded list + summary + trends + methods, four queries only');
foreach($wpdb->queries as $sql)check(strpos($sql,'SELECT *')===false&&!preg_match('/\bmessage\b/',$sql),'No bodies in list queries');
check(strpos($html,'LIMIT')===false&&strpos($html,'&lt;img src=x')!==false&&strpos($html,'PRIVATE BODY')===false,'List escapes titles and excludes private body');
if(($argv[1]??'')==='visual'){
    echo '<!doctype html><html><head><base href="https://example.test/"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{font:14px Arial;margin:16px;background:#f0f0f1}input,select{box-sizing:border-box}table{width:100%;border-collapse:collapse}td,th{padding:10px;text-align:left}.button{padding:8px;display:inline-block}dialog:not([open]){display:none}</style>';
    foreach($assets as $asset)if($asset[0]==='wu-email-admin'&&substr($asset[1],-4)==='.css')echo '<link rel="stylesheet" href="'.esc_attr($asset[1]).'">';
    echo '</head><body>'.$html.'<script>window.wuEmailAdmin='.json_encode($GLOBALS['localized']['wuEmailAdmin']).';</script>';
    foreach($assets as $asset)if($asset[0]==='wu-email-admin'&&substr($asset[1],-3)==='.js')echo '<script src="'.esc_attr($asset[1]).'"></script>';
    echo '</body></html>';exit;
}
if(($argv[1]??'')==='detail'){echo json_encode(['success'=>true,'data'=>$detail[1]]);exit;}
echo "Email tracking passed: privacy, bounded content, filters, retention, independent quotas, protected detail and scoped queries/assets.\n";
