<?php
defined('ABSPATH') || exit;
/** PAYUNi subscription extraction; no pet app, frontend SPA or account entitlements. */
final class WUTM_PAYUNi_Subscriptions {
    static $problem='';
    static function conflict(){
        $active=array_merge((array)get_option('active_plugins',[]),array_keys((array)get_site_option('active_sitewide_plugins',[])));
        foreach($active as $plugin)if(preg_match('~^(pet-management|payuni-subscriptions|payuni-v2-subscriptions|woomp)/~',$plugin))return true;
        return defined('WUPET_PAYUNI_URL')||defined('WOOMP_PLUGIN_DIR');
    }
    static function engine(){
        if(self::conflict()){self::$problem='偵測到其他 PAYUNi 訂閱處理器，請先停用其中一套，避免重複扣款。';return;}
        if(PHP_VERSION_ID<80100||!class_exists('WooCommerce')||!extension_loaded('openssl')){self::$problem='需要 PHP 8.1、WooCommerce 與 OpenSSL。';return;}
        if(!class_exists('WC_Subscriptions')){
            require_once __DIR__.'/vendor/woocommerce-subscriptions/woocommerce-subscriptions.php';
        }
    }
    static function gateway(){
        if(self::$problem||self::conflict())return;
        if(!class_exists('WC_Payment_Gateway')||!function_exists('wcs_get_subscriptions_for_order')){self::$problem='訂閱引擎尚未就緒，請查看 WooCommerce Subscriptions 的版本需求提示。';return;}
        defined('WUTM_SUB_PAYUNI_URL')||define('WUTM_SUB_PAYUNI_URL',WUTM_URL.'modules/payuni-subscriptions/');
        defined('WUTM_SUB_PAYUNI_VERSION')||define('WUTM_SUB_PAYUNI_VERSION',WUTM_VERSION);
        defined('WUTM_SUB_PAYUNI_V2')||define('WUTM_SUB_PAYUNI_V2',true);
        defined('WUTM_SUB_PAYUNI_INCLUDES_URL')||define('WUTM_SUB_PAYUNI_INCLUDES_URL',WUTM_SUB_PAYUNI_URL.'includes/payuni/');
        spl_autoload_register(static function($class){$prefix='WUTMPayuniSubscription\\';if(strncmp($class,$prefix,strlen($prefix))!==0)return;$relative=str_replace('\\','/',substr($class,strlen($prefix)));$relative=preg_replace_callback('#^[^/]+#',static fn($m)=>strtolower($m[0]),$relative);$file=__DIR__.'/includes/payuni/src/'.$relative.'.php';if(is_file($file))require_once $file;});
        $map=['payuni_payment_testmode'=>'testmode','payuni_3d_auth'=>'auth3d','payuni_payment_merchant_no'=>'merchant','payuni_payment_merchant_no_test'=>'merchant_test','payuni_payment_hash_key'=>'hash_key','payuni_payment_hash_key_test'=>'hash_key_test','payuni_payment_hash_iv'=>'hash_iv','payuni_payment_hash_iv_test'=>'hash_iv_test'];
        foreach($map as $option=>$field)add_filter('pre_option_wutm_sub_'.$option,static function($pre)use($field){$s=get_option('woocommerce_payuni-credit-subscription_settings',[]);return $s[$field]??(in_array($field,['testmode','auth3d'],true)?'yes':'');});
        require_once __DIR__.'/gateway.php';require_once __DIR__.'/renewal.php';require_once __DIR__.'/admin-subscriptions.php';
        add_filter('woocommerce_payment_gateways',static function($list){$list[]=WUTM_SUB_PAYUNI_Gateway::class;return $list;});
        class_exists(WUTMPayuniSubscription\Gateways\Response::class);class_exists(WUTMPayuniSubscription\Gateways\PendingReconciler::class);
        add_action('woocommerce_scheduled_subscription_payment_payuni-credit-subscription',[WUTM_SUB_PAYUNI_Renewal::class,'run'],10,2);
    }
    static function page(){
        if(!current_user_can('manage_options'))return;
        wutm_admin_page_open('PAYUNi 定期訂閱','信用卡首期付款、3D 驗證、排程續扣與失敗對帳；沿用訂閱商品及 WooCommerce 訂閱管理。');
        if(!class_exists('WUTM_SUB_PAYUNI_Gateway')){echo '<p>'.esc_html(self::$problem?:'訂閱付款尚未載入，請檢查訂閱引擎及外掛衝突。').'</p>';wutm_admin_page_close();return;}
        echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=wu-payuni-subscription-list')).'">訂閱管理</a> <a class="button" href="'.esc_url(admin_url('edit.php?post_type=product')).'">訂閱商品設定</a></p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wutm_subscription_save">';wp_nonce_field('wutm_subscription_save');(new WUTM_SUB_PAYUNI_Gateway())->admin_options();submit_button('儲存訂閱付款設定');echo '</form><p>預設使用沙盒；請先完成首刷、3D 通知、續扣、停止續訂及失敗對帳測試。正式環境須 HTTPS。本模組沿用來源的本站信用卡輸入流程，不是跳轉至金流收款頁；訂閱金鑰與一般 PAYUNi 付款設定分開。</p>';wutm_admin_page_close();
    }
}
add_action('plugins_loaded',[WUTM_PAYUNi_Subscriptions::class,'engine'],1);
add_action('plugins_loaded',[WUTM_PAYUNi_Subscriptions::class,'gateway'],26);
add_action('admin_menu',static function(){add_submenu_page('wu-toolbox-modular','PAYUNi 定期訂閱','PAYUNi 定期訂閱','manage_options','wu-payuni-subscriptions',[WUTM_PAYUNi_Subscriptions::class,'page']);});
add_action('admin_post_wutm_subscription_save',static function(){if(!current_user_can('manage_options'))wp_die('沒有權限');check_admin_referer('wutm_subscription_save');if(!class_exists('WUTM_SUB_PAYUNI_Gateway'))wp_die('訂閱付款未載入');(new WUTM_SUB_PAYUNI_Gateway())->process_admin_options();wp_safe_redirect(admin_url('admin.php?page=wu-payuni-subscriptions&saved=1'));exit;});
add_action('admin_notices',static function(){if(WUTM_PAYUNi_Subscriptions::$problem&&current_user_can('manage_options'))echo '<div class="notice notice-warning"><p>'.esc_html(WUTM_PAYUNi_Subscriptions::$problem).'</p></div>';});
