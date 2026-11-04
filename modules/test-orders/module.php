<?php
defined('ABSPATH') || exit;
if(!class_exists('WooCommerce'))return;
add_filter('woocommerce_webhook_should_deliver',static function($deliver,$webhook,$arg){if($webhook->get_resource()==='order'){$order=wc_get_order($arg);if($order&&$order->get_meta('_khto_test')==='1')return false;}return $deliver;},999,3);
add_filter('woocommerce_can_reduce_order_stock',static function($allow,$order){return $order&&$order->get_meta('_khto_test')==='1'&&!$order->get_meta('_wutm_to_reduce')?false:$allow;},999,2);
foreach(['new_order','cancelled_order','failed_order','customer_on_hold_order','customer_processing_order','customer_completed_order','customer_refunded_order','customer_invoice','customer_note'] as $email390)add_filter('woocommerce_email_enabled_'.$email390,static function($enabled,$order){return $order instanceof WC_Order&&$order->get_meta('_khto_test')==='1'&&!$order->get_meta('_wutm_to_mail')?false:$enabled;},999,2);
if(!is_admin())return;
if(function_exists('khto_status_pool')){add_action('admin_notices',static function(){if(current_user_can('manage_woocommerce'))echo '<div class="notice notice-warning"><p>測試訂單：請先停用原 KH Test Orders 程式，避免重複操作。</p></div>';});return;}
require_once __DIR__.'/source.php';
/** Temporarily isolate third-party order automations, restoring every callback afterwards. */
function wutm_to_isolate(): array {
    global $wp_filter;$saved=[];
    foreach($wp_filter as $name=>$hook){
        if(strpos($name,'woocommerce_')!==0||strpos($name,'order')===false)continue;
        $saved[$name]=clone $hook;
        foreach($hook->callbacks as $priority=>$callbacks)foreach($callbacks as $entry){
            $fn=$entry['function'];
            try{$reflection=is_array($fn)?new ReflectionMethod($fn[0],$fn[1]):new ReflectionFunction($fn);$file=$reflection->getFileName();}catch(Throwable $error){$file=false;}
            if(!$file||strpos(wp_normalize_path($file),wp_normalize_path(WC_ABSPATH))!==0)remove_action($name,$fn,$priority);
        }
    }
    if(isset($wp_filter['pre_wp_mail']))$saved['pre_wp_mail']=clone $wp_filter['pre_wp_mail'];
    return $saved;
}
function wutm_to_restore(array $saved): void {foreach($saved as $name=>$hook)$GLOBALS['wp_filter'][$name]=$hook;}
