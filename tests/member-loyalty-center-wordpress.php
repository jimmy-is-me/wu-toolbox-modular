<?php
// Run in the isolated WordPress + WooCommerce CI installation, never production.
if (!defined('ABSPATH') || !class_exists('WooCommerce')) throw new RuntimeException('Native WP/WC required');
$root=dirname(__DIR__);foreach(['points','tiers','member-center','member-card'] as $file)require_once $root.'/modules/member-loyalty/'.$file.'.php';
function loyalty_native_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
$points=WC_Member_Points_Rewards::instance();$tiers=WC_Membership_Tiers::instance();
$points->wcmp_activate();$tiers->wmt_activate();
$admin=get_current_user_id();$user=wp_insert_user(['user_login'=>'loyalty-native-'.wp_generate_password(8,false),'user_pass'=>wp_generate_password(),'role'=>'customer','display_name'=>'Native <member>']);
loyalty_native_assert(!is_wp_error($user),'Member created');
update_option('wutm_loyalty_card',['enabled'=>true]);update_user_meta($user,'wcmp_points_balance',1250);
wp_set_current_user($user);$html=WUTM_Loyalty_Card::render();
loyalty_native_assert(strpos($html,'Native &lt;member&gt;')!==false && strpos($html,'1250')!==false,'Own card, escaped name, real points');
preg_match('/data-qr="([^"]+)"/',$html,$match);parse_str(wp_parse_url(html_entity_decode($match[1]),PHP_URL_QUERY),$query);
preg_match('/data-qr="([^"]+)"/',WUTM_Loyalty_Card::render(),$fresh_match);parse_str(wp_parse_url(html_entity_decode($fresh_match[1]),PHP_URL_QUERY),$fresh_query);
loyalty_native_assert($fresh_query['card_token']!==$query['card_token'],'Refreshing card creates a fresh opaque token');
loyalty_native_assert(!empty($query['card_token'])&&!isset($query['user_id']),'Opaque QR token');
if(!did_action('rest_api_init'))do_action('rest_api_init');
$request=new WP_REST_Request('GET','/wu-loyalty/v1/card');$request->set_param('token',$query['card_token']);
loyalty_native_assert(rest_do_request($request)->get_status()===403,'Customer cannot look up card identities');
wp_set_current_user(0);loyalty_native_assert(rest_do_request($request)->get_status()===401,'Anonymous cannot look up card identities');
loyalty_native_assert(strpos(WUTM_Loyalty_Card::render(),'請先登入')!==false,'Anonymous card privacy');
wp_set_current_user($admin);$response=rest_do_request($request);
loyalty_native_assert($response->get_status()===200 && $response->get_data()['member_id']===$user,'Authorized staff sees real member');
loyalty_native_assert($points->wcmp_get_balance($user)===1250,'Scanning does not spend points');
$tokenkey='wutm_card_'.hash('sha256',$query['card_token']);set_transient($tokenkey,['id'=>$user,'expires'=>time()-1],300);
loyalty_native_assert(rest_do_request($request)->get_status()===410,'Expired card rejected');
// Real WC coupons and real issued-coupon rows, including UTC/site timezone boundary.
$previous_timezone=get_option('timezone_string');update_option('timezone_string','Asia/Taipei');global $wpdb;$codes=[];
foreach(['active'=>time()+3600,'expired'=>time()-3600,'used'=>time()+3600] as $state=>$expires){
 $coupon=new WC_Coupon();$code='native-'.$state.'-'.wp_generate_password(6,false);$coupon->set_code($code);$coupon->set_amount(10);$coupon->set_date_expires($expires);$coupon->set_usage_limit(1);if($state==='used')$coupon->set_usage_count(1);$coupon->save();$codes[]=$coupon->get_id();
 $wpdb->insert($wpdb->prefix.'wmt_coupons_issued',['user_id'=>$user,'tier_id'=>1,'type'=>'birthday','coupon_code'=>$code,'month_key'=>wp_date('Y-m'),'issued_at'=>current_time('mysql')]);
}
$method=new ReflectionMethod($tiers,'wmt_render_member_coupons_html');$method->setAccessible(true);$coupons=$method->invoke($tiers,$user);
loyalty_native_assert(substr_count($coupons,'is-available')===1 && substr_count($coupons,'is-unavailable')===2,'Real WC coupon status and timezone');
loyalty_native_assert(substr_count($coupons,'data-coupon-copy=')===1,'Expired/used coupon has no copy action');
update_option('wutm_loyalty_card',['enabled'=>false]);loyalty_native_assert(WUTM_Loyalty_Card::render()==='','Setting disables card');
update_option('timezone_string',$previous_timezone);foreach($codes as $id)wp_delete_post($id,true);$wpdb->delete($wpdb->prefix.'wmt_coupons_issued',['user_id'=>$user]);wp_delete_user($user);delete_transient($tokenkey);wp_set_current_user($admin);
echo "Native member center checks passed: WP/WC rendering, REST permissions, expiry, no point spending and real coupon statuses.\n";
