<?php
defined('ABSPATH') || exit;

/** Resolve the product presented by a public storefront or a mapped content page. */
final class WUTM_ATM_Content {
    public static function page(): array {
        $url = WUTM_ATM_Events::source_url();
        if (is_404() || is_feed() || is_preview() || is_admin() || wp_doing_ajax() || wp_is_json_request()) {
            return ['kind'=>'ignore','product'=>0,'url'=>$url];
        }
        if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-received') || is_wc_endpoint_url('order-pay'))) {
            return ['kind'=>'receipt','product'=>0,'url'=>$url];
        }
        if (class_exists('WUTM_ATM_Woo_Events') && is_checkout() && !is_wc_endpoint_url()) {
            return ['kind'=>'checkout','product'=>0,'url'=>$url];
        }
        $id = 0;
        if (class_exists('WUTM_ATM_Woo_Events') && WUTM_ATM_Events::is_enabled('ViewContent') && is_singular() &&
            !is_front_page() && !is_cart() && !is_account_page() && !is_wc_endpoint_url()) {
            $post = get_queried_object();
            if ($post instanceof WP_Post) $id = self::resolve($post);
        }
        return ['kind'=>$id ? 'product' : (is_search() ? 'search' : 'page'),'product'=>$id,'url'=>$url];
    }

    public static function resolve(WP_Post $post): int {
        if (!function_exists('wc_get_product') || !is_post_publicly_viewable($post) || $post->post_password !== '') return 0;
        try {
            $native = $post->post_type === 'product';
            $id = $native ? $post->ID : self::tutor_product_id($post);
            // Integrations may return a published product/variation ID or zero to suppress ViewContent.
            $id = apply_filters('wutm_atm_content_product_id', $id, $post);
            if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id <= 0) return 0;
            $id = (int) $id;
            $product = self::product($id);
            if (!$product) return 0;
            // Use the initial selection on a native product page. Integrations may map directly to a variation.
            if ($native && $id === $post->ID && $product->is_type('variable')) {
                $variation = self::variation($product);
                if ($variation) return $variation;
            }
            return self::price($product) !== null ? $id : 0;
        } catch (Throwable $error) {
            // Third-party resolution must not interrupt the storefront.
            return 0;
        }
    }

    private static function tutor_product_id(WP_Post $post): int {
        if (!function_exists('tutor') || !function_exists('tutor_utils') || $post->post_type !== (tutor()->course_post_type ?? null)) {
            return 0;
        }
        $utils = tutor_utils();
        if (!is_callable([$utils,'get_course_product_id']) || !is_callable([$utils,'get_option']) ||
            $utils->get_option('monetize_by') !== 'wc') return 0;
        if (is_callable([$utils,'is_course_purchasable']) && !$utils->is_course_purchasable($post->ID)) return 0;
        return (int) $utils->get_course_product_id($post->ID);
    }

    public static function product(int $id): ?WC_Product {
        $product = wc_get_product($id);
        if (!$product instanceof WC_Product || $product->get_status() !== 'publish') return null;
        $post = get_post($product->get_parent_id() ?: $id);
        if (!$post instanceof WP_Post || $post->post_status !== 'publish' || $post->post_password !== '') return null;
        return $product;
    }

    public static function price(WC_Product $product): ?float {
        if ($product->is_type('variable')) {
            $price = $product->get_variation_price('min', true);
        } else {
            if (!is_numeric($product->get_price())) return null;
            $price = wc_get_price_to_display($product);
        }
        return is_numeric($price) && is_finite((float) $price) && (float) $price >= 0 ? (float) $price : null;
    }

    private static function variation(WC_Product_Variable $product): int {
        $selection = [];
        foreach ($product->get_attributes() as $attribute) {
            if (!$attribute->get_variation()) continue;
            $name = sanitize_title($attribute->get_name());
            $key = 'attribute_' . $name;
            $value = $_GET[$key] ?? $product->get_variation_default_attribute($name);
            if (!is_string($value) || $value === '') return 0;
            $selection[$key] = $attribute->is_taxonomy() ? sanitize_title(wp_unslash($value)) :
                html_entity_decode(wc_clean(wp_unslash($value)), ENT_QUOTES, get_bloginfo('charset'));
        }
        if (!$selection) return 0;
        $id = (int) WC_Data_Store::load('product')->find_matching_product_variation($product, $selection);
        $variation = $id ? self::product($id) : null;
        return $variation && $variation->is_type('variation') && $variation->get_parent_id() === $product->get_id() && self::price($variation) !== null ? $id : 0;
    }
}
