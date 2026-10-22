<?php
/** Complete user-supplied V3 report, admin-only and independently switchable. */
defined('ABSPATH') || exit;
if(!is_admin())return;

function wutm_order_report_export_conflict(): bool {
    foreach(['WCTRE_Order_Export_V3','WCTRE_Order_Export_V2','WCTRE_Simple_Order_Export'] as $class)if(class_exists($class,false))return true;
    return false;
}
function wutm_order_report_export_notice(): void {
    if(current_user_can('manage_woocommerce')||current_user_can('manage_options'))echo '<div class="notice notice-warning"><p>訂單報表匯出：偵測到原有匯出 Snippet 仍在執行。請先停用舊程式，再使用 Toolbox 模組；個人欄位設定不會刪除。</p></div>';
}
if(wutm_order_report_export_conflict()) {add_action('admin_notices','wutm_order_report_export_notice');return;}
if(!function_exists('wc_get_orders'))return;
require_once __DIR__.'/source.php';
// Also handle snippets initialized after plugins_loaded but before admin dispatch.
add_action('admin_init',static function(){
    if(wutm_order_report_export_conflict()){
        foreach(['admin_menu'=>'menu','admin_post_wctre_v3_submit'=>'submit','wp_ajax_wctre_v3_scan'=>'ajax_scan','wp_ajax_wctre_v3_preview'=>'ajax_preview'] as $hook=>$method)remove_action($hook,['WUTM_Order_Report_Export_V3',$method],$hook==='admin_menu'?60:10);
        add_action('admin_notices','wutm_order_report_export_notice');return;
    }
    if(($_GET['page']??'')==='wctre-order-export-v3'&&(current_user_can('manage_woocommerce')||current_user_can('manage_options'))){wp_safe_redirect(admin_url('admin.php?page=wu-order-report-export'));exit;}
},100);
add_action('admin_enqueue_scripts',static function($hook){
    if(strpos((string)$hook,'wu-order-report-export')===false)return;
    wp_enqueue_style('wutm-order-report-export',WUTM_URL.'modules/order-report-export/admin.css',[],WUTM_VERSION);
});
