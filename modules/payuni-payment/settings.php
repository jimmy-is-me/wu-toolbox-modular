<?php
defined('ABSPATH') || exit;
final class WUTM_PAYUNi_Settings {
    static function init(){
        add_action('admin_menu',static function(){add_submenu_page('wu-toolbox-modular','PAYUNi 付款工具','PAYUNi 付款工具','manage_options','wu-payuni-payment',[__CLASS__,'page']);});
        add_action('admin_post_wutm_payuni_save',[__CLASS__,'save']);
        add_action('woocommerce_order_status_refunded',[__CLASS__,'auto_refund'],20);
    }
    static function fields(){
        if(!class_exists('WC_Settings_Page'))require_once WC_ABSPATH.'includes/admin/settings/class-wc-settings-page.php';
        $settings=new \WPBrewer\Payuni\Payment\Settings\SettingsTab();
        return $settings->get_settings_for_payment_section();
    }
    static function page(){
        if(!current_user_can('manage_options'))return;
        wutm_admin_page_open('PAYUNi 付款工具','管理串接設定與退款模式；付款方式仍須在 WooCommerce 個別啟用。');
        if(!class_exists('\WPBrewer\Payuni\Payment\PayuniPayment')){echo '<p>付款程式尚未載入，請確認 WooCommerce、OpenSSL 與外掛衝突提示。</p>';wutm_admin_page_close();return;}
        require_once WC_ABSPATH.'includes/admin/class-wc-admin-settings.php';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="wutm_payuni_save">';wp_nonce_field('wutm_payuni_save');
        echo '<h2>退款模式</h2><table class="form-table"><tr><th>已退款狀態的處理</th><td><select name="wutm_payuni_refund_mode">';foreach(['manual'=>'手動：由訂單的退款按鈕操作','auto'=>'自動：訂單轉為已退款時申請剩餘款項退款'] as $v=>$label)echo '<option value="'.$v.'" '.selected(get_option('wutm_payuni_refund_mode','manual'),$v,false).'>'.esc_html($label).'</option>';echo '</select><p>預設手動。兩種模式都保留「透過 PAYUNi 退款」按鈕。自動模式會在訂單轉為已退款時，對尚未透過金流退回的金額送出申請；已經在金流後台退款的訂單，請使用手動模式處理，避免重複退款。</p><p>僅支援本付款工具可退款的交易及請款狀態。失敗或結果不明會記入訂單備註，不自動重試；請核對 PAYUNi 後台。發票作廢由發票模組獨立處理。</p></td></tr></table>';
        WC_Admin_Settings::output_fields(self::fields());submit_button('儲存設定');echo '</form><p><a class="button" href="'.esc_url(admin_url('admin.php?page=wc-settings&tab=checkout')).'">設定個別付款方式</a></p>';wutm_admin_page_close();
    }
    static function save(){
        if(!current_user_can('manage_options'))wp_die('沒有權限');check_admin_referer('wutm_payuni_save');
        if(!class_exists('\WPBrewer\Payuni\Payment\PayuniPayment'))wp_die('付款程式尚未載入');
        require_once WC_ABSPATH.'includes/admin/class-wc-admin-settings.php';WC_Admin_Settings::save_fields(self::fields());
        update_option('wutm_payuni_refund_mode',($_POST['wutm_payuni_refund_mode']??'')==='auto'?'auto':'manual',false);
        wp_safe_redirect(admin_url('admin.php?page=wu-payuni-payment&saved=1'));exit;
    }
    static function auto_refund($id){
        if(get_option('wutm_payuni_refund_mode','manual')!=='auto')return;
        $order=wc_get_order($id);if(!$order||$order->get_meta('_wutm_payuni_auto_refund_attempt'))return;
        $gateways=WC()->payment_gateways()->payment_gateways();$gateway=$gateways[$order->get_payment_method()]??null;
        if(!$gateway||!is_a($gateway,'WPBrewer\Payuni\Payment\Gateways\GatewayBase')||!$gateway->supports('refunds'))return;
        $paid_back=0;foreach($order->get_refunds() as $refund)if($refund->get_refunded_payment())$paid_back+=(float)$refund->get_amount();
        $amount=round((float)$order->get_total()-$paid_back,wc_get_price_decimals());if($amount<=0)return;
        $key='wutm_payuni_auto_refund_'.(int)$id;if(!add_option($key,time(),'','no'))return;
        try{
            $order=wc_get_order($id);if($order->get_meta('_wutm_payuni_auto_refund_attempt'))return;
            $order->update_meta_data('_wutm_payuni_auto_refund_attempt',['amount'=>$amount,'time'=>time()]);$order->save();
            $result=$gateway->process_refund($id,$amount,'訂單轉為已退款，自動申請退款');
            if($result===true){$order->update_meta_data('_wutm_payuni_auto_refund_success',$amount);$order->save();$order->add_order_note('WU PAYUNi 自動退款申請成功，金額：'.$amount.'。實際入帳以金流紀錄為準。');}
            else{$order->add_order_note('WU PAYUNi 自動退款未確認成功，請至金流後台核對；系統不會自動重試。'.(is_wp_error($result)?' '.$result->get_error_message():''));}
        }catch(\Throwable $e){$order->add_order_note('WU PAYUNi 自動退款未確認成功，請至金流後台核對；系統不會自動重試。');}
        finally{delete_option($key);}
    }
}
WUTM_PAYUNi_Settings::init();
