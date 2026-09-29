<?php
/** Customer-entered WooCommerce product prices. */
defined('ABSPATH') || exit;

if (!class_exists('WooCommerce')) return;

final class WUTM_Product_Custom_Price {
    private const ENABLED = '_wumetax_custom_price_enabled';
    private const MIN = '_wumetax_custom_price_min';
    private const MAX = '_wumetax_custom_price_max';
    private const DEFAULT = '_wumetax_custom_price_default';
    private const STEP = '_wumetax_custom_price_step';
    private const LABEL = '_wumetax_custom_price_label';
    private const INPUT = 'wumetax_custom_price';
    private const CART_VALUE = 'wumetax_custom_price';
    private const CART_UNIQUE = 'wumetax_custom_price_unique';

    public static function boot(): void {
        add_action('woocommerce_product_options_pricing', [__CLASS__, 'admin_fields']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_admin_fields']);
        add_action('woocommerce_before_add_to_cart_button', [__CLASS__, 'render_field'], 20);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend_assets'], 20);
        add_filter('woocommerce_add_to_cart_validation', [__CLASS__, 'validate_add_to_cart'], 10, 5);
        add_filter('woocommerce_add_cart_item_data', [__CLASS__, 'add_cart_item_data'], 10, 3);
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'apply_cart_prices'], 20);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'display_cart_price'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'save_order_price'], 10, 4);
        add_filter('woocommerce_get_price_html', [__CLASS__, 'display_product_price'], 20, 2);
    }

    public static function admin_fields(): void {
        global $post;
        if (!$post || empty($post->ID)) return;

        echo '<div class="options_group"><p style="padding:8px 12px 0;margin:0"><strong>客戶自填金額</strong></p>';
        woocommerce_wp_checkbox([
            'id' => self::ENABLED,
            'label' => '允許客戶自填金額',
            'description' => '開啟後，客戶可在商品頁自行輸入購買金額。',
        ]);

        $fields = [
            self::MIN => ['最低金額', '例如：100。留空則最低為 1 元。', '0'],
            self::MAX => ['最高金額', '例如：10000。留空代表不限制最高金額。', '0'],
            self::DEFAULT => ['預設金額', '商品頁載入時預先顯示的金額，可留空。', '0'],
            self::STEP => ['金額級距', '建議輸入的金額單位，例如 100 代表建議以 100 元為級距。', '0.01'],
        ];
        foreach ($fields as $id => [$label, $description, $minimum]) {
            $value = get_post_meta($post->ID, $id, true);
            if ($id === self::STEP && $value === '') $value = '1';
            woocommerce_wp_text_input([
                'id' => $id,
                'label' => $label,
                'description' => $description,
                'desc_tip' => true,
                'type' => 'number',
                'value' => $value,
                'custom_attributes' => ['min' => $minimum, 'step' => '0.01'],
            ]);
        }
        woocommerce_wp_text_input([
            'id' => self::LABEL,
            'label' => '欄位名稱',
            'description' => '例如：請輸入金額、自由贊助金額或付款金額。',
            'desc_tip' => true,
            'value' => get_post_meta($post->ID, self::LABEL, true) ?: '請輸入金額',
        ]);
        echo '</div>';
    }

    public static function save_admin_fields(int $product_id): void {
        if (!current_user_can('edit_post', $product_id)) return;

        update_post_meta($product_id, self::ENABLED, isset($_POST[self::ENABLED]) ? 'yes' : 'no');
        foreach ([self::MIN, self::MAX, self::DEFAULT, self::STEP] as $key) {
            $raw = isset($_POST[$key]) ? trim((string) wp_unslash($_POST[$key])) : '';
            if ($raw === '') {
                delete_post_meta($product_id, $key);
                continue;
            }
            $value = wc_format_decimal($raw);
            if ($value === '' || !is_numeric($value) || (float) $value < 0) {
                delete_post_meta($product_id, $key);
                continue;
            }
            update_post_meta($product_id, $key, $value);
        }

        if (isset($_POST[self::LABEL])) {
            update_post_meta($product_id, self::LABEL, sanitize_text_field(wp_unslash($_POST[self::LABEL])));
        }
    }

    private static function parent_id(int $product_id): int {
        return (int) wp_get_post_parent_id($product_id);
    }

    private static function setting(int $product_id, string $key, string $default = ''): string {
        $value = get_post_meta($product_id, $key, true);
        if ($value !== '' && is_scalar($value)) return (string) $value;
        $parent_id = self::parent_id($product_id);
        if ($parent_id) {
            $value = get_post_meta($parent_id, $key, true);
            if ($value !== '' && is_scalar($value)) return (string) $value;
        }
        return $default;
    }

    public static function is_enabled(int $product_id): bool {
        if (self::setting($product_id, self::ENABLED) === 'yes') return true;
        $parent_id = self::parent_id($product_id);
        return $parent_id > 0 && get_post_meta($parent_id, self::ENABLED, true) === 'yes';
    }

    private static function normalize_input($value): string {
        if (!is_scalar($value)) return '';
        $value = (string) wp_unslash($value);
        $value = str_replace(['NT$', 'NT＄', '$', '＄', ',', ' ', "\t", "\n", "\r"], '', $value);
        return (string) wc_format_decimal($value);
    }

    private static function amount_is_valid(int $product_id, string $amount): bool {
        if ($amount === '' || !is_numeric($amount) || (float) $amount <= 0) return false;
        $value = (float) $amount;
        $min = self::setting($product_id, self::MIN, '1');
        $max = self::setting($product_id, self::MAX, '');
        if ($min !== '' && $value < (float) $min) return false;
        if ($max !== '' && $value > (float) $max) return false;
        return true;
    }

    public static function render_field(): void {
        global $product;
        if (!$product instanceof WC_Product) return;
        $product_id = (int) $product->get_id();
        if (!self::is_enabled($product_id)) return;

        $min = self::setting($product_id, self::MIN, '1');
        $max = self::setting($product_id, self::MAX);
        $default = self::setting($product_id, self::DEFAULT);
        $step = self::setting($product_id, self::STEP, '1');
        $label = self::setting($product_id, self::LABEL, '請輸入金額');
        $input_id = 'wutm-custom-price-' . $product_id;
        ?>
        <div class="wutm-custom-price-box" data-min="<?php echo esc_attr($min); ?>" data-max="<?php echo esc_attr($max); ?>" data-step="<?php echo esc_attr($step); ?>">
            <label class="wutm-custom-price-label" for="<?php echo esc_attr($input_id); ?>"><?php echo esc_html($label); ?><span class="wutm-custom-price-required" aria-hidden="true">*</span></label>
            <div class="wutm-custom-price-control">
                <span class="wutm-custom-price-currency" aria-hidden="true"><?php echo esc_html(get_woocommerce_currency_symbol()); ?></span>
                <input type="text" id="<?php echo esc_attr($input_id); ?>" name="<?php echo esc_attr(self::INPUT); ?>" class="wutm-custom-price-input" value="<?php echo esc_attr($default); ?>" inputmode="decimal" autocomplete="off" placeholder="請輸入金額" aria-required="true" aria-describedby="<?php echo esc_attr($input_id); ?>-help <?php echo esc_attr($input_id); ?>-error">
            </div>
            <div class="wutm-custom-price-message" id="<?php echo esc_attr($input_id); ?>-help">
                <?php if ($min !== '' && $max !== '') : ?>可輸入金額：<strong><?php echo wp_kses_post(wc_price($min)); ?></strong> ～ <strong><?php echo wp_kses_post(wc_price($max)); ?></strong>
                <?php elseif ($min !== '') : ?>最低金額：<strong><?php echo wp_kses_post(wc_price($min)); ?></strong><?php endif; ?>
                <?php if ($step !== '' && (float) $step > 0) : ?><span class="wutm-custom-price-step-hint">建議級距：<?php echo wp_kses_post(wc_price($step)); ?></span><?php endif; ?>
            </div>
            <div class="wutm-custom-price-error" id="<?php echo esc_attr($input_id); ?>-error" role="alert" aria-live="polite"></div>
        </div>
        <?php
    }

    public static function enqueue_frontend_assets(): void {
        if (!function_exists('is_product') || !is_product()) return;
        $product_id = (int) get_queried_object_id();
        if (!$product_id || !self::is_enabled($product_id)) return;

        wp_register_style('wutm-custom-price', false, [], WUTM_VERSION);
        wp_enqueue_style('wutm-custom-price');
        $css = '
            .wutm-custom-price-box{display:block;width:100%;box-sizing:border-box;margin:20px 0;padding:20px;background:#f6f8f7;border:1px solid #dfe7e2;border-radius:10px}
            .wutm-custom-price-box *{box-sizing:border-box}
            .wutm-custom-price-label{display:block;margin:0 0 10px;font-size:16px;font-weight:600;line-height:1.5;color:#24352b}
            .wutm-custom-price-required{margin-left:3px;color:#c43b3b}
            .wutm-custom-price-control{display:flex;align-items:stretch;width:100%;max-width:450px;min-height:54px;margin:0;background:#fff;border:1px solid #aebbb3;border-radius:8px;overflow:hidden;transition:border-color .18s ease,box-shadow .18s ease}
            .wutm-custom-price-control:focus-within{border-color:#4fa567;box-shadow:0 0 0 3px rgba(79,165,103,.16)}
            .wutm-custom-price-currency{display:flex;align-items:center;justify-content:center;flex:0 0 auto;min-width:68px;padding:0 15px;background:#eef4ef;border-right:1px solid #dbe4dd;color:#365441;font-size:17px;font-weight:600;pointer-events:none}
            .wutm-custom-price-box input.wutm-custom-price-input{appearance:none;display:block;flex:1 1 auto;width:100%;min-width:0;min-height:52px;height:52px;margin:0;padding:10px 15px;background:#fff;border:0;border-radius:0;outline:none;box-shadow:none;color:#1d2921;font-family:inherit;font-size:18px;font-weight:500;line-height:1.4;text-align:left}
            .wutm-custom-price-box input.wutm-custom-price-input:focus{outline:none;border:0;box-shadow:none}
            .wutm-custom-price-box input.wutm-custom-price-input::placeholder{color:#9aa69e;opacity:1;font-weight:400}
            .wutm-custom-price-message{margin:9px 0 0;color:#65736a;font-size:13px;line-height:1.6}
            .wutm-custom-price-message strong{color:inherit;font-weight:600}
            .wutm-custom-price-step-hint{display:block;margin-top:2px;color:#7a887f;font-size:12px}
            .wutm-custom-price-error{display:none;margin-top:7px;color:#b42318;font-size:13px;line-height:1.5}
            .wutm-custom-price-box.has-error .wutm-custom-price-error{display:block}
            .wutm-custom-price-box.has-error .wutm-custom-price-control{border-color:#c43b3b}
            @media(max-width:767px){.wutm-custom-price-box{margin:16px 0;padding:16px}.wutm-custom-price-control{max-width:none}.wutm-custom-price-currency{min-width:60px;padding:0 13px;font-size:16px}.wutm-custom-price-box input.wutm-custom-price-input{font-size:16px}}
        ';
        wp_add_inline_style('wutm-custom-price', $css);

        wp_register_script('wutm-custom-price', false, [], WUTM_VERSION, true);
        wp_enqueue_script('wutm-custom-price');
        $js = <<<'JS'
(function(){
    function clean(value){
        value=String(value).replace(/NT\$/gi,'').replace(/[,$＄\s]/g,'').replace(/[^\d.]/g,'');
        var parts=value.split('.');
        return parts.length>2 ? parts.shift()+'.'+parts.join('') : value;
    }
    function format(value){return new Intl.NumberFormat('zh-TW',{maximumFractionDigits:2}).format(value);}
    document.addEventListener('input',function(event){
        var input=event.target.closest('.wutm-custom-price-input');
        if(!input)return;
        var box=input.closest('.wutm-custom-price-box');
        input.value=clean(input.value);
        box.classList.remove('has-error');
        var error=box.querySelector('.wutm-custom-price-error');
        if(error)error.textContent='';
    });
    document.addEventListener('blur',function(event){
        if(event.target.matches('.wutm-custom-price-input'))validate(event.target,false);
    },true);
    function validate(input,showError){
        var box=input.closest('.wutm-custom-price-box'),error=box.querySelector('.wutm-custom-price-error');
        var value=parseFloat(clean(input.value)),min=parseFloat(box.dataset.min||'0');
        var max=box.dataset.max!==''?parseFloat(box.dataset.max):null,message='';
        if(!input.value.trim())message='請輸入金額。';
        else if(!Number.isFinite(value)||value<=0)message='請輸入有效的金額。';
        else if(Number.isFinite(min)&&min>0&&value<min)message='最低金額為 '+format(min)+'。';
        else if(max!==null&&Number.isFinite(max)&&value>max)message='最高金額為 '+format(max)+'。';
        box.classList.toggle('has-error',showError&&!!message);
        if(error)error.textContent=showError?message:'';
        return !message;
    }
    document.addEventListener('submit',function(event){
        var form=event.target;
        if(!form.matches('form.cart'))return;
        var input=form.querySelector('.wutm-custom-price-input');
        if(input&&!validate(input,true)){event.preventDefault();input.focus();input.closest('.wutm-custom-price-box').scrollIntoView({behavior:'smooth',block:'center'});}
    },true);
})();
JS;
        wp_add_inline_script('wutm-custom-price', $js);
    }

    public static function validate_add_to_cart(bool $passed, int $product_id, int $quantity, int $variation_id = 0, array $variations = []): bool {
        $check_id = $variation_id ?: $product_id;
        if (!self::is_enabled($check_id)) return $passed;
        $raw = $_POST[self::INPUT] ?? '';
        $amount = self::normalize_input($raw);
        if ($amount === '') {
            wc_add_notice('請輸入購買金額。', 'error');
            return false;
        }
        if (!is_numeric($amount) || (float) $amount <= 0) {
            wc_add_notice('請輸入有效的購買金額。', 'error');
            return false;
        }
        $value = (float) $amount;
        $min = self::setting($check_id, self::MIN, '1');
        $max = self::setting($check_id, self::MAX);
        if ($min !== '' && $value < (float) $min) {
            wc_add_notice(sprintf('此商品最低購買金額為 %s。', wc_price($min)), 'error');
            return false;
        }
        if ($max !== '' && $value > (float) $max) {
            wc_add_notice(sprintf('此商品最高購買金額為 %s。', wc_price($max)), 'error');
            return false;
        }
        return $passed;
    }

    public static function add_cart_item_data(array $cart_item_data, int $product_id, int $variation_id): array {
        $check_id = $variation_id ?: $product_id;
        if (!self::is_enabled($check_id) || !isset($_POST[self::INPUT])) return $cart_item_data;
        $amount = self::normalize_input($_POST[self::INPUT]);
        if (!self::amount_is_valid($check_id, $amount)) return $cart_item_data;
        $cart_item_data[self::CART_VALUE] = (float) $amount;
        $cart_item_data[self::CART_UNIQUE] = wp_generate_uuid4();
        return $cart_item_data;
    }

    public static function apply_cart_prices($cart): void {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (!$cart instanceof WC_Cart) return;
        foreach ($cart->get_cart() as $cart_item) {
            if (empty($cart_item[self::CART_VALUE]) || empty($cart_item['data']) || !$cart_item['data'] instanceof WC_Product) continue;
            $amount = (float) $cart_item[self::CART_VALUE];
            if ($amount > 0) $cart_item['data']->set_price($amount);
        }
    }

    public static function display_cart_price(array $item_data, array $cart_item): array {
        if (!isset($cart_item[self::CART_VALUE])) return $item_data;
        $formatted = wc_price((float) $cart_item[self::CART_VALUE]);
        $item_data[] = ['key' => '自訂金額', 'value' => $formatted, 'display' => $formatted];
        return $item_data;
    }

    public static function save_order_price($item, string $cart_item_key, array $values, $order): void {
        if (!isset($values[self::CART_VALUE]) || !is_object($item) || !method_exists($item, 'add_meta_data')) return;
        $amount = (float) $values[self::CART_VALUE];
        $item->add_meta_data('自訂金額', wc_price($amount), true);
        $item->add_meta_data('_wumetax_custom_price', $amount, true);
    }

    public static function display_product_price(string $price_html, $product): string {
        if (is_admin() || !$product instanceof WC_Product) return $price_html;
        $product_id = (int) $product->get_id();
        if (!self::is_enabled($product_id)) return $price_html;
        $min = self::setting($product_id, self::MIN, '');
        $max = self::setting($product_id, self::MAX, '');
        if ($min !== '' && $max !== '') return sprintf('<span class="wumetax-price-range">%s ～ %s</span>', wc_price($min), wc_price($max));
        if ($min !== '') return sprintf('<span class="wumetax-price-from">%s 起</span>', wc_price($min));
        return '<span class="wumetax-price-custom">自訂金額</span>';
    }
}

WUTM_Product_Custom_Price::boot();
