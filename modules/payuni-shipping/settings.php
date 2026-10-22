<?php
defined('ABSPATH') || exit;
function wutm_ps_settings() {
    static $page;
    if(!$page){
        if(!class_exists('WC_Settings_Page'))require_once WC_ABSPATH.'includes/admin/settings/class-wc-settings-page.php';
        $page=new class extends \WuDigital\Payuni\Shipping\Settings\SettingsTab {
            // Reuse every source field, without registering a second PAYUNi WC tab.
            public function __construct() {$this->id='payuni';$this->label='PAYUNi 物流工具';}
        };
    }
    $fields=$page->get_settings('shipping');
    foreach($fields as &$field)if(in_array($field['id']??'',['payuni_payment_hashkey','payuni_payment_hashiv'],true))$field['type']='password';
    unset($field);return $fields;
}
add_action('admin_menu',static function(){
    add_submenu_page('wu-toolbox-modular','PAYUNi 物流工具','PAYUNi 物流工具','manage_woocommerce','wu-payuni-shipping',static function(){
        if(!current_user_can('manage_woocommerce'))return;
        wutm_admin_page_open('PAYUNi 物流工具','保留 7-ELEVEN 常溫／冷凍、黑貓常溫／冷凍／冷藏、建單、貨態查詢及標籤列印／下載。');
        echo '<p>金鑰與 PAYUNi 付款工具共用；修改此處會同步影響付款串接。物流測試模式獨立設定。新安裝預設測試模式，7-ELEVEN 自動建單預設關閉。</p><p>請先以商家測試帳號驗證，再於運送區域加入物流方式。選店適用傳統結帳，尚未提供區塊結帳選店介面。</p>';
        if(!empty($_GET['saved']))echo '<div class="notice notice-success inline"><p>設定已儲存。</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wutm_save_payuni_shipping">';
        wp_nonce_field('wutm_save_payuni_shipping');WC_Admin_Settings::output_fields(wutm_ps_settings());submit_button('儲存設定');echo '</form><p><a class="button" href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=shipping')).'">設定運送區域與運費</a></p>';
        wutm_admin_page_close();
    });
});
add_action('admin_post_wutm_save_payuni_shipping',static function(){
    if(!current_user_can('manage_woocommerce'))wp_die('沒有管理商店權限。','',['response'=>403]);
    check_admin_referer('wutm_save_payuni_shipping');
    foreach(['hashkey'=>32,'hashiv'=>16] as $name=>$length){$value=wp_unslash($_POST['payuni_payment_'.$name]??'');if(!is_string($value)||($value!==''&&strlen($value)!==$length))wp_die('PAYUNi 串接金鑰長度不正確。');}
    WC_Admin_Settings::save_fields(wutm_ps_settings());wp_safe_redirect(admin_url('admin.php?page=wu-payuni-shipping&saved=1'));exit;
});
add_action('admin_enqueue_scripts',static function($hook){if(strpos((string)$hook,'wu-payuni-shipping')===false)return;wp_enqueue_style('woocommerce_admin_styles');wp_enqueue_script('wc-enhanced-select');});
add_filter('woocommerce_get_sections_payuni',static function($sections){$sections['shipping']='物流設定';return $sections;});
add_action('admin_init',static function(){if(($_GET['page']??'')==='wc-settings'&&($_GET['tab']??'')==='payuni'&&($_GET['section']??'')==='shipping'&&current_user_can('manage_woocommerce')){wp_safe_redirect(admin_url('admin.php?page=wu-payuni-shipping'));exit;}},5);
