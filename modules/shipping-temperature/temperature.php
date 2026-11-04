<?php
defined('ABSPATH') || exit;

final class WUTM_Shipping_Temperature {
    const META = '_shipping_temperature';
    const CONFLICT = '常溫與冷藏商品無法同時購買，請分開下單，或先移除不同溫層的商品。';

    public static function active(): bool {return !function_exists('stt_get_temp') && !defined('WC_STT_VERSION');}
    public static function init(): void {
        add_action('woocommerce_product_options_shipping',[__CLASS__,'product_field']);
        add_action('woocommerce_process_product_meta',[__CLASS__,'save_product']);
        add_filter('manage_edit-product_columns',[__CLASS__,'columns'],20);
        add_action('manage_product_posts_custom_column',[__CLASS__,'column'],10,2);
        add_action('admin_enqueue_scripts',[__CLASS__,'assets']);
        add_action('woocommerce_product_quick_edit_end',[__CLASS__,'quick_field']);
        add_action('woocommerce_product_bulk_edit_end',[__CLASS__,'bulk_field']);
        add_action('woocommerce_product_quick_edit_save',[__CLASS__,'save_quick']);
        add_action('woocommerce_product_bulk_edit_save',[__CLASS__,'save_bulk']);
        add_action('admin_menu',[__CLASS__,'menu']);
        add_filter('woocommerce_package_rates',[__CLASS__,'filter_rates'],20,2);
        add_filter('woocommerce_add_to_cart_validation',[__CLASS__,'validate_add'],10,5);
        add_action('woocommerce_store_api_validate_add_to_cart',[__CLASS__,'validate_store_add'],10,2);
        add_action('woocommerce_check_cart_items',[__CLASS__,'check_cart']);
        add_action('woocommerce_store_api_cart_errors',[__CLASS__,'store_cart_errors'],10,2);
        add_action('woocommerce_before_cart',[__CLASS__,'cart_notice']);
        add_action('woocommerce_before_checkout_form',[__CLASS__,'cart_notice']);
        add_filter('woocommerce_update_order_review_fragments',[__CLASS__,'notice_fragment']);
        add_action('woocommerce_after_shop_loop_item_title',[__CLASS__,'badge'],5);
        add_action('woocommerce_single_product_summary',[__CLASS__,'badge'],6);
        add_filter('woocommerce_cart_shipping_packages',[__CLASS__,'packages']);
    }
    public static function labels(): array {return ['all'=>'皆可（預設常溫，可跟隨冷藏）','room'=>'常溫運送','cold'=>'冷藏運送'];}
    public static function temp($id): string {
        $value=get_post_meta((int)$id,self::META,true);
        if ($value==='' && ($parent=wp_get_post_parent_id((int)$id))) $value=get_post_meta($parent,self::META,true);
        return is_string($value)&&isset(self::labels()[$value])?$value:'all';
    }
    public static function cart_type(array $contents): string {
        $types=[];
        foreach ($contents as $item) {
            if ((float)($item['quantity']??1)<=0) continue;
            $temp=self::temp($item['variation_id']??0 ?: ($item['product_id']??0));
            if ($temp!=='all') $types[$temp]=true;
        }
        return count($types)>1?'conflict':(isset($types['cold'])?'cold':'room');
    }
    public static function allowed(string $type): array {
        $values=get_option($type==='cold'?'stt_cold_methods':'stt_room_methods',[]);
        return is_array($values)?array_values(array_filter($values,'is_string')):[];
    }
    public static function filter_rates($rates,$package) {
        if (!self::active()) return $rates;
        $type=self::cart_type((array)($package['contents']??[]));
        if ($type==='conflict') return [];
        $allowed=self::allowed($type);
        if (!$allowed) return $rates; // Preserve original empty-list = unrestricted behavior.
        foreach ($rates as $id=>$rate) {
            $keep=false;
            foreach ($allowed as $base) if ($id===$base || strpos((string)$id,$base.':')===0) {$keep=true;break;}
            if (!$keep) unset($rates[$id]);
        }
        return $rates;
    }
    public static function packages($packages) {
        if (!self::active()) return $packages;
        foreach ($packages as &$package) {
            $temps=[];foreach ((array)($package['contents']??[]) as $key=>$item) $temps[$key]=self::temp($item['variation_id']??0 ?: ($item['product_id']??0));
            $package['wutm_shipping_temperature']=[WUTM_VERSION,$temps,self::allowed('room'),self::allowed('cold')];
        }
        unset($package);return $packages;
    }
    private static function conflicts($id): bool {
        if (!self::active() || !WC()->cart) return false;
        $new=self::temp($id);if ($new==='all') return false;
        foreach (WC()->cart->get_cart() as $item) {
            $old=self::temp($item['variation_id']??0 ?: $item['product_id']);
            if ($old!=='all' && $old!==$new && (float)$item['quantity']>0) return true;
        }
        return false;
    }
    public static function validate_add($pass,$id,$qty,$variation_id=0,$variations=[]) {
        if (!$pass) return $pass;
        if (self::conflicts($variation_id ?: $id)) {wc_add_notice(self::CONFLICT,'error');return false;}
        return $pass;
    }
    public static function validate_store_add($product,$request): void {
        if (self::conflicts($product->get_id())) throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('wutm_temperature_conflict',self::CONFLICT,400);
    }
    public static function check_cart(): void {
        if (self::active() && WC()->cart && self::cart_type(WC()->cart->get_cart())==='conflict' && !wc_has_notice(self::CONFLICT,'error')) wc_add_notice(self::CONFLICT,'error');
    }
    public static function store_cart_errors($errors,$cart): void {
        if (self::active() && self::cart_type($cart->get_cart())==='conflict') $errors->add('wutm_temperature_conflict',self::CONFLICT);
    }
    public static function cart_notice(): void {
        if (!self::active() || !WC()->cart || WC()->cart->is_empty()) return;
        $type=self::cart_type(WC()->cart->get_cart());
        echo '<div class="wutm-stt-cart-notice">';
        wc_print_notice($type==='conflict'?self::CONFLICT:'您的購物車使用'.($type==='cold'?'冷藏':'常溫').'運送；運送方式依溫層設定顯示。',$type==='conflict'?'error':'notice');
        echo '</div>';
    }
    public static function notice_fragment($fragments) {
        if (!self::active()) return $fragments;
        ob_start();self::cart_notice();$html=ob_get_clean();
        $fragments['.wutm-stt-cart-notice']=$html ?: '<div class="wutm-stt-cart-notice"></div>';
        return $fragments;
    }
    public static function badge(): void {
        global $product;if (!self::active() || !$product) return;
        $temp=self::temp($product->get_id());
        echo '<span class="wutm-stt-badge wutm-stt-'.esc_attr($temp).'" style="display:inline-block;color:#fff;background:'.($temp==='cold'?'#1565c0':($temp==='room'?'#b34a00':'#2e7d32')).';padding:2px 8px;border-radius:4px;font-size:12px;margin-bottom:5px">'.esc_html($temp==='all'?'常溫運送（可搭配冷藏）':self::labels()[$temp]).'</span>';
    }
    public static function product_field(): void {
        if (!self::active()) return;
        woocommerce_wp_select(['id'=>self::META,'label'=>'運送溫層','description'=>'皆可商品預設常溫，購物車有冷藏商品時跟隨冷藏。','desc_tip'=>true,'options'=>self::labels()]);
        wp_nonce_field('wutm_stt_product','wutm_stt_nonce');
    }
    private static function save($id,$field): void {
        if (!self::active() || !current_user_can('edit_post',$id) || !isset($_REQUEST[$field]) || !is_string($_REQUEST[$field]) || !isset($_REQUEST['wutm_stt_nonce']) || !is_string($_REQUEST['wutm_stt_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['wutm_stt_nonce'])),'wutm_stt_product')) return;
        $value=sanitize_text_field(wp_unslash($_REQUEST[$field]));
        if (!isset(self::labels()[$value])) return;
        $product=wc_get_product($id);if (!$product) return;
        $product->update_meta_data(self::META,$value);$product->save_meta_data();
    }
    public static function save_product($id): void {self::save($id,self::META);}
    public static function save_quick($product): void {self::save($product->get_id(),self::META);}
    public static function save_bulk($product): void {self::save($product->get_id(),'_stt_bulk');}
    private static function edit_field(bool $bulk): void {
        if (!self::active()) return;
        echo '<div class="wutm-stt-edit"><label>運送溫層 <select name="'.($bulk?'_stt_bulk':self::META).'">';
        if ($bulk) echo '<option value="">不變更</option>';
        foreach (self::labels() as $value=>$label) echo '<option value="'.esc_attr($value).'">'.esc_html($label).'</option>';
        echo '</select></label>';wp_nonce_field('wutm_stt_product','wutm_stt_nonce');echo '</div>';
    }
    public static function quick_field(): void {self::edit_field(false);}
    public static function bulk_field(): void {self::edit_field(true);}
    public static function columns($columns) {
        if (!self::active()) return $columns;
        $out=[];foreach ($columns as $key=>$label) {if ($key==='price')$out['shipping_temperature']='運送溫層';$out[$key]=$label;}
        if (!isset($out['shipping_temperature']))$out['shipping_temperature']='運送溫層';return $out;
    }
    public static function column($column,$id): void {
        if (!self::active() || $column!=='shipping_temperature') return;
        $temp=self::temp($id);
        echo '<span class="wutm-stt-badge wutm-stt-'.esc_attr($temp).'">'.esc_html($temp==='all'?'皆可':self::labels()[$temp]).'</span><span class="wutm-stt-data" data-temp="'.esc_attr($temp).'" hidden></span>';
    }
    public static function assets($hook): void {
        if (!self::active()) return;
        $screen=get_current_screen();if (!$screen || $screen->post_type!=='product') return;
        wp_enqueue_style('wutm-shipping-temperature',WUTM_URL.'modules/shipping-temperature/admin.css',[],WUTM_VERSION);
        if ($screen->base==='edit') wp_enqueue_script('wutm-shipping-temperature',WUTM_URL.'modules/shipping-temperature/admin.js',['jquery','inline-edit-post'],WUTM_VERSION,true);
    }
    public static function methods(): array {
        $out=[];$zones=WC_Shipping_Zones::get_zones();$zones[]=['zone_id'=>0];
        foreach ($zones as $data) {
            $zone=new WC_Shipping_Zone((int)$data['zone_id']);
            foreach ($zone->get_shipping_methods(true) as $method) $out[$method->get_rate_id()]='['.$zone->get_zone_name().'] '.$method->get_title();
        }
        return $out;
    }
    public static function menu(): void {add_submenu_page('wu-toolbox-modular','溫層限制物流','溫層限制物流','manage_woocommerce','wu-shipping-temperature',[__CLASS__,'page']);}
    public static function page(): void {
        if (!current_user_can('manage_woocommerce')) return;
        wutm_admin_page_open('溫層限制物流','商品可選皆可、常溫或冷藏；保留原商品溫層及物流對應資料。');
        if (!self::active()) {echo '<p>偵測到原 WC 運送溫層限制外掛或 Snippet，請先停用原程式再使用本模組，避免重複執行。原設定不會刪除。</p>';wutm_admin_page_close();return;}
        $methods=self::methods();
        if (isset($_POST['_stt_save'])) {
            check_admin_referer('wutm_stt_settings');
            foreach (['room','cold'] as $type) {
                $posted=$_POST[$type]??[];$clean=[];
                foreach (is_array($posted)?wp_unslash($posted):[] as $id) if (is_string($id)&&isset($methods[$id]))$clean[]=$id;
                update_option('stt_'.$type.'_methods',array_values(array_unique($clean)),false);
            }
            echo '<div class="notice notice-success inline"><p>設定已儲存。</p></div>';
        }
        echo '<p>勾選各溫層允許的運送方式。某一溫層完全未勾選時，沿用原程式規則：不限制該溫層的物流。</p><form method="post">';wp_nonce_field('wutm_stt_settings');
        echo '<div class="wutm-ui-table-scroll"><table class="widefat striped"><thead><tr><th>運送方式</th><th>常溫（允許）</th><th>冷藏（允許）</th></tr></thead><tbody>';
        if (!$methods) echo '<tr><td colspan="3">尚無啟用的運送方式，請先於 WooCommerce 運送設定建立運送區域。</td></tr>';
        foreach ($methods as $id=>$label) {
            echo '<tr><td>'.esc_html($label).'<br><small>'.esc_html($id).'</small></td>';
            foreach (['room','cold'] as $type) echo '<td><label><span class="screen-reader-text">'.esc_html($label.' '.($type==='room'?'常溫':'冷藏')).'</span><input type="checkbox" name="'.$type.'[]" value="'.esc_attr($id).'" '.checked(in_array($id,self::allowed($type),true),true,false).'></label></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div><p><button type="submit" name="_stt_save" value="1" class="button button-primary">儲存設定</button></p></form><h2>規則說明</h2><ul><li>皆可商品預設常溫；有冷藏商品時跟隨冷藏，本身不造成衝突。</li><li>常溫與冷藏商品須分開下單，加入購物車及結帳均會檢查。</li><li>商品編輯、快速編輯與批次編輯均可設定溫層；商品頁、列表及購物車保留溫層提示。</li><li>溫層與「商品限制物流」同時啟用時，顧客只能使用同時符合兩者的運送方式。</li></ul>';
        wutm_admin_page_close();
    }
}
