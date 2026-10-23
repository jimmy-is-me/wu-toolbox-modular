<?php
defined('ABSPATH') || exit;

/** Keep delivery choices distinct from the generic free-delivery rate. */
final class WUTM_Newebpay_Shipping_Rates {
    private static $rates = [];

    private static function package_key(array $package): string {
        unset($package['rates']);
        foreach ($package['contents'] ?? [] as $key => $item) {
            unset($package['contents'][$key]['data']);
        }
        return md5(wp_json_encode($package));
    }

    public static function capture($package, $method): void {
        if ($method->id !== 'wu_np_newebpay_shipping_cvs') return;
        // These are already calculated by WooCommerce, including availability,
        // shipping-class restrictions and shipping-method rate filters.
        self::$rates[self::package_key($package)] = $method->rates;
    }

    public static function retain($rates, $package) {
        $key = self::package_key($package);
        $captured = self::$rates[$key] ?? [];
        unset(self::$rates[$key]);
        if (get_option('woocommerce_shipping_hide_rates_when_free', 'no') !== 'yes'
            || WU_NP_WT::get_option('enabled_newebpay_shipping', 'no') !== 'yes'
            || WU_NP_WT::get_option('newebpay_shipping_keep_with_free', 'yes') !== 'yes') return $rates;
        foreach ($rates as $rate) {
            if ($rate->get_method_id() === 'free_shipping') {
                // Run before product/role restriction filters. Never recalculate
                // unavailable methods or restore unrelated carriers here.
                return $rates + $captured;
            }
        }
        return $rates;
    }

    public static function invalidate($option, ...$unused): void {
        if (strpos($option, 'WU_NP_WT_') !== 0 || !class_exists('WC_Cache_Helper')) return;
        $old = WC_Cache_Helper::get_transient_version('shipping');
        $new = WC_Cache_Helper::get_transient_version('shipping', true);
        // Core uses seconds; several updates in one save must still invalidate.
        if ($old === $new) set_transient('shipping-transient-version', $new . '-' . wp_generate_uuid4());
    }
}
add_filter('woocommerce_cart_shipping_packages', static function($packages) {
    foreach ($packages as &$package) $package['wutm_newebpay_rates_version'] = WUTM_VERSION;
    unset($package);
    return $packages;
});
add_action('woocommerce_after_get_rates_for_package', ['WUTM_Newebpay_Shipping_Rates', 'capture'], 10, 2);
add_filter('woocommerce_package_rates', ['WUTM_Newebpay_Shipping_Rates', 'retain'], 1, 2);
add_action('updated_option', ['WUTM_Newebpay_Shipping_Rates', 'invalidate'], 10, 3);
add_action('added_option', ['WUTM_Newebpay_Shipping_Rates', 'invalidate'], 10, 2);
add_action('deleted_option', ['WUTM_Newebpay_Shipping_Rates', 'invalidate'], 10, 1);
