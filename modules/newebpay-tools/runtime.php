<?php
defined('ABSPATH') || exit;
require_once __DIR__.'/locks.php';

/** Only the selected provider's isolated options, never another integration's switches. */
final class WU_NP_WT {
    public static $option_prefix = 'WU_NP_WT_';
    public static function get_option($key, $default=false) {
        if (strpos($key, 'ecpay') !== false || strpos($key, 'smilepay') !== false) return 'no';
        return get_option(self::$option_prefix . $key, $default);
    }
    public static function update_option($key, $value) { return update_option(self::$option_prefix . $key, $value, false); }
    public static function is_checkout_submission(): bool { return did_action('woocommerce_checkout_process') || isset($_POST['woocommerce-process-checkout-nonce']); }
}
final class WU_NP_Checkout {
    public static function get_diff_amount_by_settings($settings, $check_requires_list, $original=false): ?float {
        $requires=$settings['cost_requires'] ?? $settings['requires'] ?? '';
        if (!in_array($requires,$check_requires_list,true) || !WC()->cart) return null;
        $total=WC()->cart->get_displayed_subtotal();
        if (($settings['ignore_discounts'] ?? 'no') === 'no') {
            $total-=WC()->cart->get_discount_total();
            if (WC()->cart->display_prices_including_tax()) $total-=WC()->cart->get_discount_tax();
        }
        return (float)($settings['min_amount'] ?? 0) - round($total,wc_get_price_decimals());
    }
}

function wutm_newebpay_boot(): void {
    if (!class_exists('WooCommerce') || !class_exists('WC_Payment_Gateway') || !class_exists('WC_Shipping_Method')) return;
    if (!extension_loaded('openssl')) { wutm_newebpay_notice('藍新金物流需要主機啟用 OpenSSL。'); return; }
    if (class_exists('RY_NewebPay_Gateway',false) || defined('WOOMP_PLUGIN_DIR')) {
        wutm_newebpay_notice('偵測到另一套藍新整合已載入，WU 不重複註冊付款與物流。'); return;
    }
    define('WU_NP_WT_PLUGIN_DIR',__DIR__.'/vendor/');
    define('WU_NP_WT_PLUGIN_URL',WUTM_URL.'modules/newebpay-tools/vendor/');
    define('WU_NP_WT_VERSION',WUTM_VERSION);
    require_once WU_NP_WT_PLUGIN_DIR.'class-wu-hpos-helper.php';
    // Keep callbacks and settings registered for existing pending orders even
    // when merchant switches are off; availability controls new checkouts.
    require_once WU_NP_WT_PLUGIN_DIR.'woocommerce/gateways/newebpay/newebpay-gateway.php';
    require_once WU_NP_WT_PLUGIN_DIR.'woocommerce/shipping/newebpay/newebpay-shipping.php';
    add_filter('woocommerce_available_payment_gateways',static function($gateways) {
        foreach ($gateways as $id=>$gateway) if (strpos($id,'wu_np_newebpay_')===0 && (WU_NP_WT::get_option('enabled_newebpay_gateway','no')!=='yes' || !wutm_newebpay_configured() || get_woocommerce_currency()!=='TWD')) unset($gateways[$id]);
        return $gateways;
    },99);
    add_filter('woocommerce_shipping_methods',static function($methods) {
        if (WU_NP_WT::get_option('enabled_newebpay_shipping','no')==='yes') return $methods;
        unset($methods['wu_np_newebpay_shipping_cvs']); return $methods;
    },99);
}
function wutm_newebpay_configured(): bool {
    return (bool)WU_NP_WT::get_option('newebpay_gateway_MerchantID','') && strlen((string)WU_NP_WT::get_option('newebpay_gateway_HashKey',''))===32 && strlen((string)WU_NP_WT::get_option('newebpay_gateway_HashIV',''))===16;
}
function wutm_newebpay_notice(string $message): void {
    add_action('admin_notices',static function() use($message) { if(current_user_can('manage_woocommerce')) echo '<div class="notice notice-warning"><p>'.esc_html($message).'</p></div>'; });
}
