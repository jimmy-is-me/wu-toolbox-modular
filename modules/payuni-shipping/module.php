<?php
/** PAYUNi Shipping 1.1.1 adapter. The standalone bootstrap is never executed. */
defined('ABSPATH') || exit;

final class WUTM_PAYUNi_Shipping_Module {
    private static $problem = '';
    public static function boot() {
        $active=(array)get_option('active_plugins',[]);
        if(is_multisite())$active=array_merge($active,array_keys((array)get_site_option('active_sitewide_plugins',[])));
        foreach($active as $plugin)if(preg_match('~(?:^|/)[^/]*payuni-shipping\.php$~i',$plugin))self::$problem='已啟用獨立 PAYUNi 物流外掛，本模組不會重複載入。';
        add_action('plugins_loaded',[__CLASS__,'initialize'],25);
    }
    public static function initialize() {
        if(!self::$problem && (defined('PAYUNI_SHIPPING_PLUGIN_DIR')||class_exists('WuDigital\\Payuni\\Shipping\\PayuniShipping',false)))self::$problem='偵測到其他 PAYUNi 物流程式，本模組不會重複載入。';
        if(!self::$problem && (!class_exists('WooCommerce')||!class_exists('WC_Shipping_Method')))self::$problem='PAYUNi 物流工具需要先啟用 WooCommerce。';
        if(!self::$problem && (!function_exists('openssl_encrypt')||!function_exists('openssl_decrypt')))self::$problem='PAYUNi 物流工具需要啟用 OpenSSL。';
        if(self::$problem){add_action('admin_notices',static function(){if(current_user_can('manage_woocommerce'))echo '<div class="notice notice-warning"><p>'.esc_html(self::$problem).'</p></div>';});return;}
        define('PAYUNI_SHIPPING_PLUGIN_DIR',__DIR__.'/vendor/');
        define('PAYUNI_SHIPPING_PLUGIN_URL',WUTM_URL.'modules/payuni-shipping/vendor/');
        define('PAYUNI_SHIPPING_BASENAME',plugin_basename(__DIR__.'/vendor/wu-payuni-shipping.php'));
        define('PAYUNI_SHIPPING_TEMPLATE_DIR',PAYUNI_SHIPPING_PLUGIN_DIR.'templates/');
        define('PAYUNI_SHIPPING_VERSION',WUTM_VERSION);
        require_once PAYUNI_SHIPPING_PLUGIN_DIR.'vendor/autoload.php';
        require_once __DIR__.'/runtime.php';
        require_once __DIR__.'/settings.php';
        // Only absent settings receive the original schema's test-mode default.
        add_filter('default_option_payuni_shipping_testmode_enabled',static function(){return 'yes';});
        add_action('init',static function(){load_plugin_textdomain('wu-payuni-shipping',false,dirname(PAYUNI_SHIPPING_BASENAME).'/languages/');});
        foreach(['PayuniShipping','Api\\ShippingRequest','Api\\ShippingResponse','Admin\\OrderEdit','Admin\\OrderListTable'] as $class)call_user_func(['WuDigital\\Payuni\\Shipping\\'.$class,'init']);
        add_filter('woocommerce_shipping_methods',static function($methods){
            foreach(['711_c2c_normal'=>'SevenEleven\\C2CNormal','711_c2c_frozen'=>'SevenEleven\\C2CFrozen','tcat_normal'=>'TCat\\HDNormal','tcat_frozen'=>'TCat\\HDFrozen','tcat_refrigerated'=>'TCat\\HDRefrigerated'] as $id=>$class){
                $methods['payuni_shipping_'.$id]='WuDigital\\Payuni\\Shipping\\Providers\\'.$class;
                add_filter('woocommerce_shipping_payuni_shipping_'.$id.'_is_available',static function($available){return $available&&wutm_ps_configured()&&get_woocommerce_currency()==='TWD';});
            }
            return $methods;
        });
        wutm_ps_register_guards();
    }
}
WUTM_PAYUNi_Shipping_Module::boot();
