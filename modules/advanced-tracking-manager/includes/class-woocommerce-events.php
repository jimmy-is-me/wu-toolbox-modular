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
            add_action('woocommerce_payment_complete', [__CLASS__, 'purchase']);
            // Also handles manual and asynchronous gateways using WC paid statuses.
            add_action('woocommerce_order_payment_status_changed', [__CLASS__, 'purchase']);
        }
        if (WUTM_ATM_Events::is_enabled('AddToCart')) {
            add_action('woocommerce_add_to_cart', [__CLASS__, 'added'], 10, 6);
            if (WUTM_ATM_Events::browser_active()) {
                add_action('wutm_atm_defer_cart_event', [__CLASS__, 'store_cart_event']);
                add_action('wp_ajax_wutm_atm_cart', [__CLASS__, 'cart_response']);
                add_action('wp_ajax_nopriv_wutm_atm_cart', [__CLASS__, 'cart_response']);
                add_filter('wutm_atm_browser_cart', [__CLASS__, 'browser_cart']);
                add_action('wp_footer', [__CLASS__, 'flush_cart'], 4);
            }
        }
    }

    public static function page(): void {
        if (is_product() && WUTM_ATM_Events::is_enabled('ViewContent')) {
            $product = wc_get_product(get_queried_object_id());
            if ($product && $product->is_visible()) {
                WUTM_ATM_Events::track('ViewContent', [
                    'content_ids'=>[(string) $product->get_id()], 'content_type'=>'product', 'content_name'=>$product->get_name(),
                    'value'=>wc_get_price_to_display($product), 'currency'=>get_woocommerce_currency(),
                ], ['source'=>'woocommerce','url'=>get_permalink($product->get_id())]);
            }
        }
        if (is_order_received_page()) {
            self::browser_purchase();
            return;
        }
        if (is_checkout() && !is_wc_endpoint_url() && WC()->cart && !WC()->cart->is_empty() && WUTM_ATM_Events::is_enabled('InitiateCheckout')) {
            $ids = [];
            foreach (WC()->cart->get_cart() as $item) $ids[] = (string) ($item['variation_id'] ?: $item['product_id']);
            WUTM_ATM_Events::track('InitiateCheckout', [
                'content_ids'=>array_unique($ids), 'content_type'=>'product',
                'value'=>WC()->cart->get_total('edit'), 'currency'=>get_woocommerce_currency(),
                'num_items'=>WC()->cart->get_cart_contents_count(),
            ], ['source'=>'woocommerce','url'=>wc_get_checkout_url()]);
        }
    }

    public static function added($key, $product_id, $quantity, $variation_id, $variation, $data): void {
        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product) return;
        // This hook runs after the cart mutation in shortcode, AJAX and Store API flows.
        WUTM_ATM_Events::track('AddToCart', [
            'content_ids'=>[(string) $product->get_id()], 'content_type'=>'product',
            'value'=>wc_get_price_to_display($product, ['qty'=>$quantity]), 'currency'=>get_woocommerce_currency(), 'num_items'=>$quantity,
        ], ['source'=>'woocommerce','url'=>get_permalink($product->get_id()),'cart_session'=>true]);
    }

    public static function browser_cart(): bool {
        return !is_order_received_page() && (is_woocommerce() || is_cart() || is_checkout() || is_singular());
    }

    public static function store_cart_event(array $payload): void {
        if (!WC()->session) return;
        $queue = (array) WC()->session->get(self::CART, []);
        $queue[$payload['id']] = ['event'=>$payload,'expires'=>time()+300];
        WC()->session->set(self::CART, array_slice($queue, -15, null, true));
    }

    private static function consume_cart(): array {
        if (!WC()->session && function_exists('wc_load_cart')) wc_load_cart();
        if (!WC()->session) return [];
        $queue = (array) WC()->session->get(self::CART, []);
        WC()->session->set(self::CART, null);
        if (WUTM_ATM_Events::excluded() || empty(WUTM_ATM_Events::consent()['granted'])) return [];
        $events = [];
        foreach ($queue as $entry) {
            if ($entry['expires'] >= time()) $events[] = $entry['event'];
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
        if (self::paid($order)) return;
        $consent = WUTM_ATM_Events::excluded() ? [] : WUTM_ATM_Events::consent();
        $order->update_meta_data(self::META, $consent ?: ['granted'=>false]);
    }

    public static function capture_and_save($order): void {
        self::capture_checkout($order);
        $order->save_meta_data();
    }

    public static function paid($order): bool {
        if (!$order instanceof WC_Order || $order->has_status(['cancelled','failed','refunded','pending','checkout-draft'])) return false;
        $confirmed = $order->is_paid() && $order->get_date_paid();
        try {
            return true === apply_filters('wutm_atm_order_paid', (bool) $confirmed, $order);
        } catch (Throwable $error) {
            return false;
        }
    }

    public static function purchase($order_id): void {
        if (!WUTM_ATM_Events::server_active()) return;
        try {
            $order = wc_get_order($order_id);
            if (self::paid($order)) self::emit_purchase($order, true);
        } catch (Throwable $error) {
            // Payment state is owned by WooCommerce.
        }
    }

    private static function browser_purchase(): void {
        if (!WUTM_ATM_Events::is_enabled('Purchase') || !WUTM_ATM_Events::browser_active() || WUTM_ATM_Events::excluded()) return;
        $order = wc_get_order(absint(get_query_var('order-received')));
        $key = is_string($_GET['key'] ?? null) ? wp_unslash($_GET['key']) : '';
        if (!self::paid($order) || $key === '' || !hash_equals($order->get_order_key(), $key)) return;
        if ($order->get_customer_id() && (int) $order->get_customer_id() !== get_current_user_id()) return;
        self::emit_purchase($order, false);
    }

    private static function emit_purchase(WC_Order $order, bool $server): void {
        $paid = $order->get_date_paid() ?: $order->get_date_created();
        if (!$paid || $paid->getTimestamp() < time() - 2 * DAY_IN_SECONDS) return;
        $ids = [];
        foreach ($order->get_items() as $item) $ids[] = (string) ($item->get_variation_id() ?: $item->get_product_id());
        $consent = (array) $order->get_meta(self::META);
        $consent = (array) apply_filters('wutm_atm_order_consent', $consent, $order);
        WUTM_ATM_Events::track('Purchase', [
            'order_id'=>(string) $order->get_id(), 'content_ids'=>array_unique($ids), 'content_type'=>'product',
            'value'=>$order->get_total(), 'currency'=>$order->get_currency(),
        ], [
            'source'=>'woocommerce','key'=>'order:' . $order->get_id(), 'order_id'=>$order->get_id(),
            'time'=>$paid->getTimestamp(), 'url'=>wc_get_checkout_url(), 'recorded_consent'=>$consent,
            'server_only'=>$server, 'browser_only'=>!$server,
            'user'=>['email'=>$order->get_billing_email(), 'user_agent'=>$order->get_customer_user_agent(), 'ip'=>$order->get_customer_ip_address(), 'fbp'=>''],
        ]);
    }
}
