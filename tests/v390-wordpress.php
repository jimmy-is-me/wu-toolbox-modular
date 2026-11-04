<?php
if(!defined('WP_CLI')||!WP_CLI||getenv('WUTM_MEDIA_INTEGRATION')!=='1'||DB_NAME!=='wci_fixture'||$GLOBALS['wpdb']->prefix!=='wme_wp_')throw new RuntimeException('Disposable fixture only');
define('WUTM_VERSION','fixture');define('WUTM_URL','https://fixture.test/');
require_once ABSPATH.'wp-admin/includes/screen.php';set_current_screen('dashboard');
require dirname(__DIR__).'/core/admin-design.php';
require dirname(__DIR__).'/modules/contact-form/module.php';
require dirname(__DIR__).'/modules/test-orders/module.php';
function v390_assert($ok,$message){if(!$ok)throw new RuntimeException($message);}
$mail=0;$external=0;$last='';$uid=get_current_user_id();$products=[];$real=null;$before=wutm_to_count_test_orders();
add_filter('pre_wp_mail',static function()use(&$mail){$mail++;return true;});
add_filter('pre_http_request',static fn()=>new WP_Error('fixture','No external calls'));
add_filter('wp_redirect',static function($url)use(&$last){$last=$url;throw new RuntimeException('redirect-finished');});
function v390_run($hook){try{do_action($hook);throw new LogicException('No redirect');}catch(RuntimeException $error){if($error->getMessage()!=='redirect-finished')throw $error;}}
try {
 foreach([1,2] as $n){$product=new WC_Product_Simple();$product->set_name('Fixture '.$n);$product->set_regular_price(100);$product->set_manage_stock(true);$product->set_stock_quantity(100);$product->save();$products[]=$product;}
 $real=wc_create_order(['created_via'=>'admin']);$real->update_meta_data('_khto_test','1');$real->save();
 add_action('woocommerce_order_status_completed',static function()use(&$external){$external++;});
 $_POST=['khto_nonce'=>'unused','wutm_to_nonce'=>wp_create_nonce('wutm_to_create'),'count'=>2,'qty_min'=>5,'qty_max'=>7,'statuses'=>['completed'],'payment'=>'bacs','days'=>3,'add_shipping'=>1];$_REQUEST=$_POST;
 v390_run('admin_post_wutm_to_create');
 v390_assert(wutm_to_count_test_orders()===$before+2,'Generated test order count');
 $orders=wc_get_orders(['limit'=>10,'created_via'=>'khto','meta_key'=>'_khto_test','meta_value'=>'1']);
 foreach($orders as $order){v390_assert($order->get_item_count()>=5&&$order->get_item_count()<=7,'Quantity range retained');v390_assert($order->get_status()==='completed'&&(float)$order->get_shipping_total()===60.0,'Status and fixed shipping retained');}
 foreach($products as $product)v390_assert((int)wc_get_product($product->get_id())->get_stock_quantity()===100,'No default stock reduction');
 v390_assert($external===0,'Third-party order automations isolated');
 $_POST=['wutm_to_nonce'=>wp_create_nonce('wutm_to_delete')];$_REQUEST=$_POST;v390_run('admin_post_wutm_to_delete');v390_assert(wutm_to_count_test_orders()===0&&wc_get_order($real->get_id()),'Cleanup never deletes real order with accidental test flag');
 do_action('init');
 v390_assert(shortcode_exists('wu_contact_form')&&shortcode_exists('kh_mini_form_x7'),'Both form shortcodes retained');
 $captcha=wutm_cf_make_captcha();$answer=explode('|',$captcha['token'])[0];v390_assert(wutm_cf_check_captcha($captcha['token'],$answer)&&!wutm_cf_check_captcha($captcha['token'],$answer.'bad'),'Captcha numeric validation');
 ob_start();wutm_cf_settings_page();$settings=ob_get_clean();v390_assert(strpos($settings,'name="khmf_recipient"')!==false&&strpos($settings,'wutm-ui-root')!==false,'Legacy settings and common layout');
 wp_set_current_user(0);$_SERVER['REMOTE_ADDR']='192.0.2.90';delete_transient('khmf_rl_'.md5($_SERVER['REMOTE_ADDR']));delete_transient('wutm_cf_rl_'.md5($_SERVER['REMOTE_ADDR']));
 $_POST=['wutm_cf_nonce'=>wp_create_nonce('wutm_cf_submit'),'wutm_cf_back'=>home_url('/'),'wutm_cf_token'=>$captcha['token'],'wutm_cf_answer'=>$answer,'wutm_cf_f_0'=>'測試姓名','wutm_cf_f_1'=>'test@example.test','wutm_cf_f_2'=>'0900000000','wutm_cf_f_3'=>'測試留言'];$_REQUEST=$_POST;
 v390_run('admin_post_nopriv_wutm_cf_submit');$entries=get_posts(['post_type'=>'khmf_entry','numberposts'=>10]);
 v390_assert(count($entries)===1&&count(get_post_meta($entries[0]->ID,'_khmf_data',true))===4,'Guest submission preserves full stored fields');
 $after=count($entries);$_POST['wutm_cf_f_0']=['invalid'];v390_run('admin_post_nopriv_wutm_cf_submit');v390_assert(count(get_posts(['post_type'=>'khmf_entry','numberposts'=>10]))===$after,'Malformed submission cannot write');
 foreach($entries as $entry)wp_delete_post($entry->ID,true);
 wp_set_current_user($uid);require dirname(__DIR__).'/modules/contact-widgets/module.php';
 $handler=new \WUTM\ContactWidgets\Admin\YSChatAjaxHandler();$method=new ReflectionMethod($handler,'sanitize_settings');$method->setAccessible(true);
 $settings=$method->invoke($handler,['enabled'=>1,'show_desktop'=>1,'show_mobile'=>1,'layout'=>'expanded','back_to_top'=>1,'mobile_outer'=>40,'mobile_inner'=>32,'apps'=>[['key'=>'facebook','value'=>'brand']]]);
 v390_assert($settings['layout']==='expanded'&&$settings['mobile_outer']===40&&$settings['back_to_top']===1,'Widget new settings persist');
 \WUTM\ContactWidgets\Database\YSChatSettingsRepo::set('settings',$settings);$frontend=new \WUTM\ContactWidgets\Frontend\YSChatFrontend();ob_start();$frontend->render_widget();$widget=ob_get_clean();
 v390_assert(strpos($widget,'facebook.com/brand')!==false&&strpos($widget,'ysch-back-top')!==false&&strpos($widget,'ysch-direct')!==false,'Widget Facebook/top/direct render');
 echo "v3.9.0 native modules passed: test order safety/counts/cleanup, quantities/status/shipping, contact shortcodes/settings, captcha, guest records and widget settings.\n";
} finally {wp_set_current_user($uid);$_POST=$_REQUEST=[];foreach($products as $product)$product->delete(true);if($real)$real->delete(true);}
