<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Woo_Events {
    const META = '_wutm_atm_consent';
    const CART = 'wutm_atm_cart_events';

    public static function boot(): void {
        add_action('template_redirect', [__CLASS__, 'page'], 12);
        if (WUTM_ATM_Events::is_enabled('Purchase')) {
            add_action('woocommerce_checkout_create_order', [__CLASS__, 'capture_checkout']);
            add_action('woocommerce_store_api_checkout_order_processed', [__CLASS__, 'capture_and_save']);
            add_action('woocommerce_before_pay_action', [__CLASS__, 'capture_and_save']);
            require_once __DIR__ . '/class-purchase.php';
            add_action('woocommerce_pre_payment_complete', ['WUTM_ATM_Purchase', 'pre_payment']);
            add_action('woocommerce_before_order_object_save', ['WUTM_ATM_Purchase', 'observe_payment_save']);
            add_action('woocommerce_payment_complete', ['WUTM_ATM_Purchase', 'standard_complete']);
            add_action('woocommerce_order_payment_status_changed', [__CLASS__, 'purchase']);
            add_action('woocommerce_order_status_changed', ['WUTM_ATM_Purchase', 'status_changed'], 10, 3);
            add_action('wutm_atm_retry_payment_complete', ['WUTM_ATM_Purchase', 'standard_complete']);
            add_action('wutm_atm_retry_payment_status', ['WUTM_ATM_Purchase', 'status_changed'], 10, 3);
            add_action('wutm_atm_retry_purchase_check', [__CLASS__, 'purchase']);
            if (is_admin() && WUTM_ATM_Purchase::active()) {
                require_once __DIR__ . '/class-purchase-admin.php';
                WUTM_ATM_Purchase_Admin::boot();
            }
        }
        if (WUTM_ATM_Events::is_enabled('AddToCart')) {
            add_action('woocommerce_add_to_cart', [__CLASS__, 'added'], 10, 6);
            if (WUTM_ATM_Events::browser_active()) {
                add_action('wutm_atm_defer_cart_event', [__CLASS__, 'store_cart_event']);
                if (!WUTM_ATM_Consent::active()) {
                    add_action('wp_ajax_wutm_atm_cart', [__CLASS__, 'cart_response']);
                    add_action('wp_ajax_nopriv_wutm_atm_cart', [__CLASS__, 'cart_response']);
                    add_filter('wutm_atm_browser_cart', [__CLASS__, 'browser_cart']);
                    add_action('wp_footer', [__CLASS__, 'flush_cart'], 4);
                }
            }
        }
    }

    public static function page(): void {
        if (is_order_received_page()) {
            self::browser_purchase();
            return;
        }
        if (WUTM_ATM_Consent::active()) return;
        $page = WUTM_ATM_Content::page();
        if ($page['kind'] === 'product') self::view_product($page['product'], null, false, $page['url']);
        if ($page['kind'] === 'checkout') self::start_checkout(null, $page['url']);
    }

    public static function view_product(int $id, ?string $key = null, bool $browser_only = false, string $url = '', bool $match = false): void {
        if (!WUTM_ATM_Events::is_enabled('ViewContent')) return;
        $product = WUTM_ATM_Content::product($id);
        if (!$product || ($price = WUTM_ATM_Content::price($product)) === null) return;
        WUTM_ATM_Events::track('ViewContent', [
            'content_ids'=>[(string) $id], 'content_type'=>'product', 'content_name'=>$product->get_name(),
            'value'=>$price, 'currency'=>get_woocommerce_currency(),
        ], ['source'=>'woocommerce','url'=>$url ?: (string) get_permalink($id),'key'=>$key,'browser_only'=>$browser_only,
            'server_only'=>$match,'retry_matching'=>$match]);
    }

    public static function start_checkout(?string $key = null, string $url = '', bool $match = false): void {
        if (!WUTM_ATM_Events::is_enabled('InitiateCheckout')) return;
        if (!WC()->cart && function_exists('wc_load_cart')) wc_load_cart();
        if (WC()->cart && !WC()->cart->is_empty()) {
            $ids = [];
            foreach (WC()->cart->get_cart() as $item) $ids[] = (string) ($item['variation_id'] ?: $item['product_id']);
            WUTM_ATM_Events::track('InitiateCheckout', [
                'content_ids'=>array_unique($ids), 'content_type'=>'product',
                'value'=>WC()->cart->get_total('edit'), 'currency'=>get_woocommerce_currency(),
                'num_items'=>WC()->cart->get_cart_contents_count(),
            ], ['source'=>'woocommerce','url'=>$url ?: wc_get_checkout_url(),'key'=>$key,
                'server_only'=>$match,'retry_matching'=>$match]);
        }
    }

    public static function added($key, $product_id, $quantity, $variation_id, $variation, $data): void {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) return;
        // This hook runs after the cart mutation in shortcode, AJAX and Store API flows.
        WUTM_ATM_Events::track('AddToCart', [
            'content_ids'=>[(string) $product->get_id()], 'content_type'=>'product',
            'value'=>wc_get_price_to_display($product, ['qty'=>$quantity]), 'currency'=>get_woocommerce_currency(), 'num_items'=>$quantity,
        ], ['source'=>'woocommerce','url'=>self::cart_source((int) $product_id),'cart_session'=>true]);
    }

    public static function cart_source(int $product_id): string {
        $referrer = is_string($_SERVER['HTTP_REFERER'] ?? null) ? wp_unslash($_SERVER['HTTP_REFERER']) : '';
        $source = WUTM_ATM_Events::verified_url($referrer);
        if ($source !== '') return $source;
        if (!wp_doing_ajax() && !wp_is_json_request() && !is_admin() &&
            !(defined('REST_REQUEST') && REST_REQUEST) && !(defined('WC_DOING_AJAX') && WC_DOING_AJAX)) {
            $source = WUTM_ATM_Events::request_url();
        }
        return $source ?: (string) get_permalink($product_id);
    }

    public static function browser_cart(): bool {
        return !is_order_received_page() && (is_woocommerce() || is_cart() || is_checkout() || is_singular());
    }

    public static function store_cart_event(array $payload): void {
        if (!WC()->session) return;
        $queue = (array) WC()->session->get(self::CART, []);
        $queue[$payload['id']] = ['event'=>$payload,'expires'=>time()+300];
        if (WUTM_ATM_Consent::active()) $queue[$payload['id']]['receipt'] = WUTM_ATM_Consent::marketing()['reference'] ?? '';
        WC()->session->set(self::CART, array_slice($queue, -15, null, true));
    }

    public static function consume_cart(): array {
        if (!WC()->session && function_exists('wc_load_cart')) wc_load_cart();
        if (!WC()->session) return [];
        $queue = (array) WC()->session->get(self::CART, []);
        WC()->session->set(self::CART, null);
        $consent = WUTM_ATM_Events::consent();
        if (WUTM_ATM_Events::excluded() || empty($consent['granted'])) return [];
        $events = [];
        foreach ($queue as $entry) {
            if ($entry['expires'] < time()) continue;
            if (WUTM_ATM_Consent::active() && ($entry['receipt'] ?? '') !== $consent['reference']) continue;
            $events[] = $entry['event'];
        }
        return $events;
    }

    public static function flush_cart(): void {
        if (!self::browser_cart()) return;
        foreach (self::consume_cart() as $payload) WUTM_ATM_Events::add_browser_payload($payload);
    }

    public static function cart_response(): void {
        check_ajax_referer('wutm_atm_cart', 'nonce');
        nocache_headers();
        wp_send_json_success(self::consume_cart());
    }

    public static function capture_checkout($order): void {
        if (!$order instanceof WC_Order || self::paid($order)) return;
        $consent = WUTM_ATM_Events::excluded() ? [] : WUTM_ATM_Events::consent();
        $order->update_meta_data(self::META, $consent ?: ['granted'=>false]);
        $url = WUTM_ATM_Events::verified_url((string) wc_get_checkout_url());
        if ($url !== '') $order->update_meta_data(WUTM_ATM_Purchase::CHECKOUT_URL, $url);
    }

    public static function capture_and_save($order): void {
        if (!$order instanceof WC_Order) return;
        try {
            self::capture_checkout($order);
            $order->save_meta_data();
        } catch (Throwable $error) {
            // Consent storage must not interrupt order payment.
        }
    }

    public static function paid($order): bool {
        require_once __DIR__ . '/class-purchase.php';
        return (bool) WUTM_ATM_Purchase::payment($order);
    }

    public static function purchase($order_id): void {
        if (!WUTM_ATM_Events::server_active()) return;
        WUTM_ATM_Purchase::locked((int) $order_id, static function (WC_Order $order) {
            return WUTM_ATM_Purchase::emit($order, true);
        }, static function () use ($order_id) {
            WUTM_ATM_Purchase::schedule_retry('wutm_atm_retry_purchase_check', [(int) $order_id]);
        });
    }

    private static function browser_purchase(): void {
        if (!WUTM_ATM_Events::is_enabled('Purchase') || !WUTM_ATM_Events::browser_active() || WUTM_ATM_Events::excluded()) return;
        $id = absint(get_query_var('order-received'));
        $key = is_string($_GET['key'] ?? null) ? wp_unslash($_GET['key']) : '';
        if ($key === '') return;
        WUTM_ATM_Purchase::locked($id, static function (WC_Order $order) use ($key) {
            if (!hash_equals($order->get_order_key(), $key)) return;
            if ($order->get_customer_id() && (int) $order->get_customer_id() !== get_current_user_id()) return;
            $consent = WUTM_ATM_Events::consent();
            if (empty($consent['granted']) || ($consent['reference'] ?? '') !== (WUTM_ATM_Purchase::consent($order)['reference'] ?? '')) return;
            return WUTM_ATM_Purchase::emit($order, false);
        });
    }
}
