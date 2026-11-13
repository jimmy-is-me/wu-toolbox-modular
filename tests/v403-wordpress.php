<?php
if (!defined('WP_CLI') || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture') throw new RuntimeException('Disposable fixture only');
require dirname(__DIR__).'/modules/contact-widgets/src/YSChatApps.php';
require dirname(__DIR__).'/modules/user-switcher/module.php';
require dirname(__DIR__).'/modules/enhanced-user-list/module.php';
function v403_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
$uid=get_current_user_id();$customer=0;$order=null;
try {
 $app='WUTM\\ContactWidgets\\YSChatApps';
 v403_assert($app::build_url('line','401example')==='https://line.me/R/ti/p/%40401example','Missing official @ encoded');
 foreach(['https://line.me/ti/p/opaque-token','https://line.me/R/ti/p/%40example','https://lin.ee/abc?openExternalBrowser=1'] as $url) v403_assert($app::build_url('line',$url)===$url,'Full invitation preserved');
 v403_assert(has_action('admin_post_wutm_switch_back')!==false,'Return endpoint registered');
 $customer=wp_create_user('v403_customer','fixture-password','v403@example.test');
 $order=wc_create_order(['customer_id'=>$customer]);
 ob_start();WUTM_User_Switcher::order_switch_link($order);$html=ob_get_clean();
 v403_assert(strpos($html,'wutm_switch_user')!==false && strpos($html,'_wpnonce')!==false,'Authorized order link has nonce');
 wp_set_current_user($customer);ob_start();WUTM_User_Switcher::order_switch_link($order);$html=ob_get_clean();v403_assert($html==='','Customer cannot switch users');
 wp_set_current_user($uid);
 $method=new ReflectionMethod(WUTM_User_Switcher::class,'cookie_value');
 $_COOKIE['wutm_switch_origin']=$method->invoke(null,$uid);
 ob_start();WUTM_User_Switcher::return_notice();$html=ob_get_clean();v403_assert(strpos($html,'wutm_switch_back')!==false,'Return available without admin bar');
 $_COOKIE['wutm_switch_origin']='tampered';ob_start();WUTM_User_Switcher::return_notice();v403_assert(ob_get_clean()==='','Forged return cookie denied');
 $profile=new WU_Enhanced_User_List();$GLOBALS['pagenow']='profile.php';ob_start();$profile->hide_user_profile_options();$html=ob_get_clean();
 foreach(['user-wordpress-wrap','user-medium-wrap','user-vimeo-wrap','user-vkontakte-wrap','user-odnoklassniki-wrap'] as $class)v403_assert(strpos($html,$class)!==false,'Requested profile row hidden');
 $GLOBALS['pagenow']='users.php';ob_start();$profile->hide_connection_tooltip();v403_assert(strpos(ob_get_clean(),'jetpack-connection-tooltip-icon')!==false,'Tooltip hidden on users screen');
 echo "v4.0.3 native checks passed: LINE URLs, order permissions/nonces, return route and forged cookies, profile visibility.\n";
} finally {wp_set_current_user($uid);unset($_COOKIE['wutm_switch_origin']);if($order)$order->delete(true);if($customer){require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($customer);}}
