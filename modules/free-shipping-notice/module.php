<?php
defined('ABSPATH') || exit;

function wutm_fs_defaults(): array { return ['enabled'=>1,'missing_text'=>'再買 {amount} 即可享免運費！','success_text'=>'您的訂單已達免運門檻！','bg_color'=>'#111111','text_color'=>'#ffffff']; }
function wutm_fs_options(): array { return wp_parse_args((array)get_option('wutm_free_shipping_notice',[]),wutm_fs_defaults()); }
function wutm_fs_sanitize($input): array { $input=is_array($input)?$input:[];return ['enabled'=>empty($input['enabled'])?0:1,'missing_text'=>sanitize_text_field($input['missing_text']??''),'success_text'=>sanitize_text_field($input['success_text']??''),'bg_color'=>sanitize_hex_color($input['bg_color']??'')?:'#111111','text_color'=>sanitize_hex_color($input['text_color']??'')?:'#ffffff']; }

function wutm_fs_add_threshold_field(array $fields): array { $fields['wutm_free_shipping_threshold']=['title'=>'免運門檻 (NT$)','type'=>'price','description'=>'商品金額扣除一般折價券後（不含運費及稅）達此金額時，此運送方式自動免運；新會員優惠不會降低免運門檻。留空或 0 表示停用。','default'=>'','desc_tip'=>true];return $fields; }
add_filter('woocommerce_shipping_instance_form_fields_flat_rate','wutm_fs_add_threshold_field');
add_filter('woocommerce_shipping_instance_form_fields_local_pickup','wutm_fs_add_threshold_field');

/**
 * 免運門檻採用商品金額扣除一般折價券後的金額。
 * WooCommerce 的 cart_contents_total 僅包含商品項目、不包含 fee，
 * 因此「新會員優惠」的負費用永遠不會降低免運門檻。
 */
function wutm_fs_cart_subtotal(): float {
    if (!function_exists('WC') || !WC()->cart) return 0.0;
    return max(0, (float) WC()->cart->get_cart_contents_total());
}

/** 已套用且設定「允許免運費」的折價券，優先提供真正的免運。 */
function wutm_fs_has_coupon_free_shipping(): bool {
    if (!function_exists('WC') || !WC()->cart || !class_exists('WC_Coupon')) return false;
    foreach ((array) WC()->cart->get_applied_coupons() as $code) {
        try {
            $coupon = new WC_Coupon($code);
            if ($coupon->get_id() && $coupon->get_free_shipping()) return true;
        } catch (Throwable $error) {
            continue;
        }
    }
    return false;
}

function wutm_fs_zero_rate($rate): void {
    if (!is_object($rate)) return;
    if (method_exists($rate, 'set_cost')) $rate->set_cost(0);
    else $rate->cost = 0;
    $taxes = method_exists($rate, 'get_taxes') ? (array) $rate->get_taxes() : (array) ($rate->taxes ?? array());
    $taxes = array_fill_keys(array_keys($taxes), 0);
    if (method_exists($rate, 'set_taxes')) $rate->set_taxes($taxes);
    else $rate->taxes = $taxes;
}
function wutm_fs_threshold($rate): float { $settings=get_option('woocommerce_'.$rate->method_id.'_'.$rate->instance_id.'_settings',[]);return max(0,(float)($rate->method_id==='seven_eleven_pickup'?($settings['free_shipping_threshold']??get_option('wu_woo_711_free_shipping_threshold',0)):($settings['wutm_free_shipping_threshold']??0))); }
add_filter('woocommerce_package_rates',function($rates,$package){
    if(is_admin()&&!wp_doing_ajax())return $rates;
    if(wutm_fs_has_coupon_free_shipping()){
        foreach($rates as $rate) wutm_fs_zero_rate($rate);
        return $rates;
    }
    $subtotal=wutm_fs_cart_subtotal();
    foreach($rates as $id=>$rate){
        if(!in_array($rate->method_id,['flat_rate','local_pickup','seven_eleven_pickup'],true))continue;
        $threshold=wutm_fs_threshold($rate);
        if($rate->method_id==='seven_eleven_pickup')continue;
        if($threshold>0&&$subtotal>=$threshold)wutm_fs_zero_rate($rates[$id]);
    }
    return $rates;
},20,2);
add_filter('woocommerce_cart_shipping_method_full_label',function($label,$rate){
    $o=wutm_fs_options();
    if(!$o['enabled']||!in_array($rate->method_id,['flat_rate','local_pickup','seven_eleven_pickup'],true))return $label;
    if(wutm_fs_has_coupon_free_shipping())$text='已套用免運折價券，目前選擇的運送方式免運費。';
    else {
        $threshold=wutm_fs_threshold($rate);if($threshold<=0)return $label;
        $subtotal=wutm_fs_cart_subtotal();
        $text=$subtotal>=$threshold?$o['success_text']:str_replace('{amount}',wp_strip_all_tags(wc_price($threshold-$subtotal)),$o['missing_text']);
    }
    return $label.'<span class="wutm-fs-notice" style="display:block;width:max-content;max-width:100%;margin-top:8px;padding:5px 9px;border-radius:5px;background:'.esc_attr($o['bg_color']).';color:'.esc_attr($o['text_color']).';font-size:13px;font-weight:400">'.esc_html($text).'</span>';
},10,2);

add_action('admin_init',function():void{register_setting('wutm_fs_group','wutm_free_shipping_notice',['type'=>'array','sanitize_callback'=>'wutm_fs_sanitize','default'=>wutm_fs_defaults()]);});
add_action('admin_menu',function():void{add_submenu_page('wu-toolbox-modular','免運門檻提示設定','免運門檻提示','manage_woocommerce','wu-free-shipping-notice','wutm_fs_admin_page');});
function wutm_fs_admin_page():void{if(!current_user_can('manage_woocommerce'))return;$o=wutm_fs_options();$zones=WC_Shipping_Zones::get_zones();$zones[]=['id'=>0,'zone_name'=>'世界其他地區（預設）'];?>
<div class="wrap"><h1>免運門檻提示</h1><p class="wutm-module-subtitle">統一管理單一費率、自行取貨及 7-11 超商物流。免運門檻以商品金額扣除一般折價券後計算（不含運費及稅）；新會員優惠不會降低免運門檻。若套用「允許免運費」折價券，則目前選擇的運送方式會直接免運。</p><form method="post" action="options.php"><?php settings_fields('wutm_fs_group');?><table class="form-table"><tr><th>顯示免運提示</th><td><label><input type="checkbox" name="wutm_free_shipping_notice[enabled]" value="1" <?php checked($o['enabled']);?>> 在購物車與結帳頁顯示</label></td></tr><tr><th>未達門檻文字</th><td><input class="regular-text" name="wutm_free_shipping_notice[missing_text]" value="<?php echo esc_attr($o['missing_text']);?>"><p class="description"><code>{amount}</code> 會替換成尚差金額。</p></td></tr><tr><th>已達門檻文字</th><td><input class="regular-text" name="wutm_free_shipping_notice[success_text]" value="<?php echo esc_attr($o['success_text']);?>"></td></tr><tr><th>提示背景色</th><td><input type="color" name="wutm_free_shipping_notice[bg_color]" value="<?php echo esc_attr($o['bg_color']);?>"></td></tr><tr><th>提示文字色</th><td><input type="color" name="wutm_free_shipping_notice[text_color]" value="<?php echo esc_attr($o['text_color']);?>"></td></tr></table><?php submit_button('儲存提示設定');?></form>
<h2>運送方式門檻</h2><p>請點擊各運送方式的「編輯」，即可設定基本運費與新增的「免運門檻」欄位。</p><table class="widefat striped"><thead><tr><th>運送區域</th><th>運送方式</th><th>目前門檻</th><th>設定</th></tr></thead><tbody><?php $found=false;foreach($zones as $zone_data):$zone=new WC_Shipping_Zone((int)$zone_data['id']);foreach($zone->get_shipping_methods() as $method):if(!in_array($method->id,['flat_rate','local_pickup','seven_eleven_pickup'],true))continue;$found=true;$threshold=$method->get_option($method->id==='seven_eleven_pickup'?'free_shipping_threshold':'wutm_free_shipping_threshold', $method->id==='seven_eleven_pickup'?get_option('wu_woo_711_free_shipping_threshold',0):0);$url=admin_url('admin.php?page=wc-settings&tab=shipping&instance_id='.(int)$method->instance_id);?><tr><td><?php echo esc_html($zone->get_zone_name());?></td><td><?php echo esc_html($method->get_title());?></td><td><?php echo $threshold!==''?wp_kses_post(wc_price((float)$threshold)):'未啟用';?></td><td><a class="button" href="<?php echo esc_url($url);?>">編輯運送方式</a></td></tr><?php endforeach;endforeach;if(!$found):?><tr><td colspan="4">尚未找到支援的運送方式。請先啟用 7-11 超商物流，並在 WooCommerce 運送區域新增配送方式。</td></tr><?php endif;?></tbody></table></div><?php }
