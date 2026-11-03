<?php
/** Isolated licensing client; no real network or database writes. */
define('ABSPATH',__DIR__);define('HOUR_IN_SECONDS',3600);define('MINUTE_IN_SECONDS',60);define('WUTM_VERSION','fixture');
$options=[];$home='https://temporary.example.test/';$allowed=true;$bad_nonce=false;$serial=0;$requests=[];$reply=[];$wp_version='fixture';
function add_action(...$args){}
function get_option($key,$default=false){return $GLOBALS['options'][$key]??$default;}
function update_option($key,$value,...$args){$GLOBALS['options'][$key]=$value;}
function delete_option($key){unset($GLOBALS['options'][$key]);}
function home_url($path=''){return rtrim($GLOBALS['home'],'/').'/'.ltrim($path,'/');}
function esc_url_raw($value,...$args){return $value;}
function wp_parse_url($value,$part=-1){return parse_url($value,$part);}
function trailingslashit($value){return rtrim($value,'/').'/';}
function absint($value){return abs((int)$value);}
function wp_is_uuid($value){return (bool)preg_match('/^[a-f0-9-]{36}$/D',$value);}
function wp_generate_uuid4(){return 'aaaaaaaa-1111-4444-aaaa-'.sprintf('%012d',++$GLOBALS['serial']);}
function sanitize_text_field($value){return strip_tags((string)$value);}
function sanitize_key($value){return preg_replace('/[^a-z0-9_-]/','',strtolower($value));}
function wp_clear_scheduled_hook(...$args){}
function wp_schedule_single_event(...$args){}
function current_user_can(...$args){return $GLOBALS['allowed'];}
function check_admin_referer(...$args){if($GLOBALS['bad_nonce'])throw new RuntimeException('nonce');}
function wp_die($message){throw new RuntimeException($message);}
function wp_safe_remote_post($url,$args){$GLOBALS['requests'][]=[$url,$args];return $GLOBALS['reply'];}
function is_wp_error($value){return false;}
function wp_remote_retrieve_body($reply){return json_encode($reply);}
function wp_remote_retrieve_response_code($reply){return $reply['http_code']??200;}
function verify($ok,$label){if(!$ok)throw new RuntimeException($label);}
require dirname(__DIR__).'/core/license-manager.php';
$client=WUTM_License_Manager::instance();$uuid=$client->site_uuid();
verify($client->site_uuid()===$uuid,'Same site keeps stable UUID');
$home='http://www.temporary.example.test/';verify($client->site_uuid()===$uuid,'Protocol and www changes preserve identity');
$options[WUTM_License_Manager::TOKEN_OPTION]='old-token';
$options[WUTM_License_Manager::STATE_OPTION]=['valid'=>true,'validated_at'=>time(),'site_url'=>$home,'site_identity'=>'temporary.example.test'];
verify($client->is_valid(),'Original valid binding');
// Reproduce global URL replacement during a database migration.
$home='https://production.example.test/';
$options[WUTM_License_Manager::IDENTITY_OPTION]='production.example.test';
$options[WUTM_License_Manager::STATE_OPTION]['site_url']=$home;
$options[WUTM_License_Manager::STATE_OPTION]['site_identity']='production.example.test';
verify(!$client->is_valid(),'Hash detects URL-rewritten old validation');
$new=$client->site_uuid();verify($new!==$uuid&&!$client->token()&&!$client->is_valid(),'New domain rotates UUID and invalidates old local token');
verify($client->site_uuid()===$new&&count($requests)===0,'Rotation stable and never deactivates old website');
// Old clients have no hash and all old domain evidence may be replaced.
delete_option(WUTM_License_Manager::IDENTITY_HASH_OPTION);
$reply=['code'=>'site_mismatch','message'=>'existing binding','http_code'=>409];
$before=$client->site_uuid();$result=$client->activate('VALID-KEY');
verify(!$result['success']&&$client->site_uuid()===$before&&count($requests)===1,'A conflict does not silently retry or bypass server binding');
$saved=$options;$bad_nonce=true;
try{$client->handle_rebind();throw new LogicException('Missing nonce guard');}catch(RuntimeException $error){verify($error->getMessage()==='nonce','Nonce required');}
verify($options===$saved,'Bad nonce cannot change identity');$bad_nonce=false;$allowed=false;
try{$client->handle_rebind();throw new LogicException('Missing capability guard');}catch(RuntimeException $error){verify($error->getMessage()==='權限不足。','Admin required');}
verify($options===$saved,'Nonadmin cannot change identity');$allowed=true;$_POST=[];
try{$client->handle_rebind();throw new LogicException('Missing confirmation');}catch(RuntimeException $error){verify($error->getMessage()==='請先確認已完成網域搬移。','Explicit move confirmation required');}
$client->reset_site_identity();verify($client->site_uuid()!==$before&&count($requests)===1,'Explicit recovery creates new identity without releasing old record');
$reply=['activation_token'=>'new-token','valid'=>true,'status'=>'active'];
verify($client->activate('VALID-KEY')['success']&&$client->is_valid(),'New identity can bind through normal server authorization');
verify($requests[1][1]['body']['site_url']===$home&&$requests[1][1]['body']['site_uuid']===$client->site_uuid(),'Wire uses official domain and new UUID');
$client->reset_site_identity();$reply=['code'=>'site_limit','message'=>'網站名額已滿','http_code'=>403];
verify(!$client->activate('VALID-KEY')['success']&&!$client->is_valid(),'Quota remains enforced after reset');
foreach(['revoked','expired','suspended'] as $code){$reply=['code'=>$code,'message'=>$code,'http_code'=>403];verify(!$client->activate('VALID-KEY')['success']&&!$client->is_valid(),'Server denial enforced: '.$code);}
$base=$client->site_uuid();$home='https://production.example.test/subsite/';verify($client->site_uuid()!==$base,'Different subdirectory is a different installation');
verify(!array_filter($requests,static fn($request)=>str_ends_with($request[0],'/deactivate')),'No automatic deactivation requests');
echo "License domain migration passed: URL-replacement detection, stable UUID, canonical URLs, explicit recovery, nonce/capability, no automatic release and server quotas.\n";
