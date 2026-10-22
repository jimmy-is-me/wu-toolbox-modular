<?php
defined('ABSPATH') || exit;
function wutm_newebpay_sections(): array { return ['services'=>'服務開關','gateway'=>'藍新金流','shipping'=>'藍新物流','invoice'=>'EZPAY 電子發票']; }
function wutm_newebpay_fields(string $section): array {
    if ($section==='invoice') $fields=wutm_invoice_settings_page('ezpay')->get_settings();
    elseif ($section==='gateway') $fields=require __DIR__.'/vendor/woocommerce/gateways/newebpay/includes/settings-newebpay-gateway.php';
    elseif ($section==='shipping') $fields=require __DIR__.'/vendor/woocommerce/shipping/newebpay/includes/settings-newebpay-shipping.php';
    else $fields=[
        ['title'=>'WU 藍新金物流服務','type'=>'title','id'=>'wu_np_services','desc'=>'新服務預設關閉。啟用金流後，仍須在 WooCommerce 付款設定開啟個別付款方式；物流須於運送區域加入「藍新超商取貨」。'],
        ['title'=>'藍新金流','type'=>'checkbox','id'=>'WU_NP_WT_enabled_newebpay_gateway','default'=>'no','desc'=>'啟用藍新付款；請先於「藍新金流」填寫商店串接資料。'],
        ['title'=>'藍新物流','type'=>'checkbox','id'=>'WU_NP_WT_enabled_newebpay_shipping','default'=>'no','desc'=>'啟用藍新超商取貨。需要啟用藍新金流，並允許配送至非帳單地址。'],
        ['type'=>'sectionend','id'=>'wu_np_services'],
    ];
    foreach($fields as &$field) {
        if(preg_match('/(HashKey|HashIV|hashkey|hashiv)(?:_test)?$/',$field['id']??'')) $field['type']='password';
    }
    unset($field); return $fields;
}
function wutm_newebpay_section($value): string { return is_string($value)&&isset(wutm_newebpay_sections()[$value])?$value:'services'; }
add_action('admin_menu',static function() {
    add_submenu_page('wu-toolbox-modular','WU 藍新金流／物流／EZPAY 電子發票工具','WU 藍新金流／物流／EZPAY 電子發票工具','manage_woocommerce','wu-newebpay-tools',static function() {
        if(!current_user_can('manage_woocommerce')) return;
        wutm_admin_page_open('WU 藍新金流／物流／EZPAY 電子發票工具','金流、物流與電子發票分開設定；啟用前請以測試商店完成付款、通知、取貨及發票驗證。');
        if(!class_exists('WooCommerce')||!class_exists('WC_Admin_Settings')) { echo '<p>請先啟用 WooCommerce。</p>'; wutm_admin_page_close(); return; }
        $section=wutm_newebpay_section($_GET['section']??'services');
        echo '<nav class="wutm-ui-tabs" aria-label="服務設定">';
        foreach(wutm_newebpay_sections() as $key=>$label) echo '<a class="button '.($key===$section?'button-primary':'').'" href="'.esc_url(admin_url('admin.php?page=wu-newebpay-tools&section='.$key)).'"'.($key===$section?' aria-current="page"':'').'>'.esc_html($label).'</a> ';
        echo '</nav><p>適用傳統結帳與傳統訂單儲存；目前不宣告完整區塊結帳／HPOS 相容。原綠界工具可繼續處理金物流；同一訂單請只使用一種發票服務。</p>';
        if($section==='invoice'&&($problem=wutm_invoice_runtime_problem('ezpay'))) echo '<div class="notice notice-warning inline"><p>'.esc_html($problem).'</p></div>';
        if(!empty($_GET['saved'])) echo '<div class="notice notice-success inline"><p>設定已儲存。</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wutm_save_newebpay_tools"><input type="hidden" name="section" value="'.esc_attr($section).'">';
        wp_nonce_field('wutm_save_newebpay_tools');
        WC_Admin_Settings::output_fields(wutm_newebpay_fields($section)); submit_button('儲存設定'); echo '</form>';
        if($section==='services'||$section==='gateway') echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')).'">設定個別付款方式</a></p>';
        if($section==='services'||$section==='shipping') echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=shipping')).'">設定運送區域與運費</a></p><p>藍新超商取貨於藍新付款流程選店；物流狀態及出貨操作以服務商後台為準。</p>';
        wutm_admin_page_close();
    });
});
add_action('admin_enqueue_scripts',static function($hook) {
    if(strpos((string)$hook,'wu-newebpay-tools')===false||!current_user_can('manage_woocommerce')) return;
    wp_enqueue_style('woocommerce_admin_styles'); wp_enqueue_script('wc-enhanced-select');
});
add_action('admin_post_wutm_save_newebpay_tools',static function() {
    if(!current_user_can('manage_woocommerce')) wp_die('沒有管理商店權限。','',['response'=>403]);
    check_admin_referer('wutm_save_newebpay_tools');
    if(!class_exists('WC_Admin_Settings')) wp_die('請先啟用 WooCommerce。');
    if(!is_string($_POST['section']??null)||!isset(wutm_newebpay_sections()[$_POST['section']])) wp_die('無效的設定分頁。');
    $section=wutm_newebpay_section($_POST['section']??'services');
    if($section==='services'&&!empty($_POST['WU_NP_WT_enabled_newebpay_shipping'])&&(empty($_POST['WU_NP_WT_enabled_newebpay_gateway'])||get_option('woocommerce_ship_to_destination')==='billing_only')) wp_die('藍新物流需要啟用金流，並允許配送至非帳單地址。');
    if($section==='services'&&!empty($_POST['WU_NP_WT_enabled_newebpay_gateway'])) {
        $id=WU_NP_WT::get_option('newebpay_gateway_MerchantID',''); $key=WU_NP_WT::get_option('newebpay_gateway_HashKey',''); $iv=WU_NP_WT::get_option('newebpay_gateway_HashIV','');
        if(!$id||strlen($key)!==32||strlen($iv)!==16) wp_die('請先設定有效的藍新 MerchantID、32 字元 HashKey 與 16 字元 HashIV。');
    }
    if($section==='invoice'&&!empty($_POST['wc_woomp_enabled_ezpay_invoice'])&&($problem=wutm_invoice_runtime_problem('ezpay'))) wp_die(esc_html($problem));
    if($section==='gateway') {
        $prefix=wp_unslash($_POST['WU_NP_WT_newebpay_gateway_order_prefix']??'');
        if(!preg_match('/^[A-Za-z0-9]{0,12}$/D',$prefix)) wp_die('訂單前綴限 12 字元內英數字。');
        foreach(['HashKey'=>32,'HashIV'=>16] as $name=>$length) { $value=wp_unslash($_POST['WU_NP_WT_newebpay_gateway_'.$name]??''); if($value!==''&&strlen($value)!==$length) wp_die('藍新串接金鑰長度不正確。'); }
    }
    WC_Admin_Settings::save_fields(wutm_newebpay_fields($section));
    wp_safe_redirect(admin_url('admin.php?page=wu-newebpay-tools&section='.$section.'&saved=1')); exit;
});
