<?php
// Render the production methods with isolated data. No database writes or WP hooks run.
define('ABSPATH', __DIR__); define('WUTM_URL','https://shop.example.test/plugin/'); define('WUTM_VERSION','fixture');
function current_user_can($cap){return true;}function wp_nonce_field(...$a){}function checked($a,$b,$echo=true){return $a===$b?'checked':'';}
function add_action(...$a){} function add_shortcode(...$a){} function wp_enqueue_style(...$a){} function wp_enqueue_script(...$a){}
function wp_parse_args($a,$b){return array_merge($b,$a);} function esc_html($v){return htmlspecialchars((string)$v,ENT_QUOTES);} function esc_attr($v){return esc_html($v);} function esc_url($v){return esc_attr($v);}
function get_option($key,$default=false){return ['wutm_loyalty_card'=>['enabled'=>true],'wcmp_settings'=>['enable_points'=>'yes','enable_earn'=>'yes','enable_redeem'=>'yes']][$key]??$default;}
function is_user_logged_in(){return true;} function get_current_user_id(){return 7;} function wp_get_current_user(){return (object)['display_name'=>'測試會員 <safe>'];}
function get_user_meta($id,$key,$single){return $key==='wcmp_points_balance'?1250:($key==='billing_birthday'?'1990-01-02':'');}
function is_wc_endpoint_url($name=null){return $name===null || $name==='vip-tier';} function wc_get_account_endpoint_url($key){return 'https://shop.example.test/account/'.$key;}
function wp_generate_password(...$a){return str_repeat('a',48);} function set_transient(...$a){} function admin_url($p){return 'https://shop.example.test/wp-admin/'.$p;}
function add_query_arg($a,$url){return $url.'?'.http_build_query($a);} function wp_timezone(){return new DateTimeZone('Asia/Taipei');} function current_datetime(){return new DateTimeImmutable('now',wp_timezone());}
function current_time($f){return current_datetime()->format($f==='mysql'?'Y-m-d H:i:s':$f);} function date_i18n($f,$t=null){return date($f,$t??time());} function absint($v){return abs((int)$v);}
function wc_get_coupon_id_by_code($code){return ['ACTIVE-CODE'=>1,'EXPIRED-CODE'=>2,'USED-CODE'=>3][$code]??0;}
class WC_Coupon{private $id;function __construct($id){$this->id=$id;}function get_id(){return $this->id;}function get_date_expires(){return new DateTimeImmutable($this->id===2?'yesterday':'+30 days');}function get_usage_limit(){return 1;}function get_usage_count(){return $this->id===3?1:0;}}
class LoyaltyFixtureDB {
 public $prefix='wp_';public $tier;
 function __construct(){ $this->tier=(object)['id'=>1,'name'=>'銀卡會員','level'=>1,'badge_url'=>'','discount_percent'=>5,'points_multiplier'=>2,'birthday_coupon_amount'=>0,'free_shipping_always'=>0,'free_shipping_per_month'=>0,'single_amount'=>0,'cum_amount'=>0,'cum_amount_days'=>365,'cum_count'=>0,'cum_count_days'=>180]; }
 function prepare($sql,...$args){return $sql;}
 function get_row($sql){if(strpos($sql,'wmt_user_tier')!==false)return (object)['tier_id'=>1,'expires_at'=>current_datetime()->modify('+5 days')->format('Y-m-d H:i:s')];return $this->tier;}
 function get_var($sql){return 800;}
 function get_results($sql){
  if(strpos($sql,'wmt_tiers')!==false){$next=clone $this->tier;$next->id=2;$next->name='金卡會員';$next->level=2;$next->cum_amount=empty($GLOBALS['single_only'])?2000:0;$next->single_amount=empty($GLOBALS['single_only'])?0:1000;$next->discount_percent=10;return [$this->tier,$next];}
  if(strpos($sql,'wmt_coupons_issued')!==false)return array_map(static function($code){return (object)['type'=>'free_shipping','coupon_code'=>$code,'month_key'=>'2026-11'];},['ACTIVE-CODE','EXPIRED-CODE','USED-CODE','MISSING-CODE']);
  if(strpos($sql,'SUM(remaining)')!==false)return [(object)['expire_date'=>date('Y-m-d',time()+86400*5),'pts'=>100]];
  if(strpos($sql,'member_points_ledger')!==false)return [(object)['created_at'=>'2026-11-17 12:00:00','type'=>'earn','amount'=>1500,'order_id'=>88,'reason'=>'完成訂單回饋'],(object)['created_at'=>'2026-11-16 12:00:00','type'=>'redeem','amount'=>-250,'order_id'=>87,'reason'=>'訂單點數折抵']];
  return [];
 }
}
$wpdb=new LoyaltyFixtureDB;
require dirname(__DIR__).'/modules/member-loyalty/points.php';
require dirname(__DIR__).'/modules/member-loyalty/tiers.php';
require dirname(__DIR__).'/modules/member-loyalty/member-center.php';
require dirname(__DIR__).'/modules/member-loyalty/member-card.php';
$tier=WC_Membership_Tiers::instance();
$html=$tier->wmt_shortcode_dashboard([]);
ob_start();WC_Member_Points_Rewards::instance()->wcmp_my_points_endpoint_content();$points=ob_get_clean();
$card=WUTM_Loyalty_Card::render();
foreach(['可使用','已過期','已使用','已失效','金卡會員','2,000','目前等級','data-member-expires'] as $text){if(strpos($html,$text)===false)throw new RuntimeException('Missing '.$text);}
if(substr_count($html,'data-coupon-copy=')!==1)throw new RuntimeException('Only usable coupons can be copied');
if(strpos($card,'&lt;safe&gt;')===false||strpos($card,'data-qr=')===false)throw new RuntimeException('Card escapes user data and includes QR');
if(strpos($points,'1250')===false||strpos($points,'data-label="說明"')===false)throw new RuntimeException('Real balance and responsive ledger required');
if(strpos(wutm_loyalty_expiry(''),'永久有效')===false||strpos(wutm_loyalty_expiry('invalid'),'確認會員效期')===false)throw new RuntimeException('Expiry fallback required');
$GLOBALS['single_only']=true;$progress=$tier->wmt_shortcode_progress([]);if(strpos($progress,'單筆消費達 1,000')===false || strpos($progress,'沒有更高')!==false)throw new RuntimeException('Single-order-only upgrade is not highest tier');$GLOBALS['single_only']=false;
if(strpos(wutm_loyalty_expiry('2026-02-31 00:00:00'),'確認會員效期')===false)throw new RuntimeException('Invalid calendar date must not be normalized');
if(in_array('--admin-html',$argv??[],true)){ob_start();WUTM_Loyalty_Card::page();echo ob_get_clean();exit;}
if(in_array('--html',$argv??[],true)){echo $html.$points.$card;}else{echo "Member center checks passed: production renderers, coupon states, real balance, tiers, expiry and escaping.\n";}
