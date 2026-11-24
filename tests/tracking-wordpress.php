<?php
if (!defined('WP_CLI') || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture') {
    throw new RuntimeException('Disposable WordPress fixture only.');
}

function wutm_tracking_assert($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$options = [
    'wutm_atm_events'=>['enabled'=>true,'mode'=>'both','woo'=>true,'events'=>['Purchase','AddToCart','Lead'],'test_code'=>'TEST123'],
    'atm_fb_pixel_id'=>'123456789', 'atm_fb_pixel_enabled'=>1, 'atm_fb_capi_enabled'=>1,
    'wutm_atm_privacy'=>['enabled'=>false],
    'atm_fb_capi_token'=>'fixture-only', 'atm_exclude_admin'=>0, 'atm_google_ads_id'=>'AW-123456/fixture',
];
$previous = [];
foreach ($options as $name => $value) {
    $previous[$name] = get_option($name);
    update_option($name, $value);
}
$allowed = false;
add_filter('wutm_atm_marketing_consent', static function () use (&$allowed) {
    return $allowed ? ['granted'=>true,'reference'=>'fixture-receipt'] : false;
});
add_filter('wutm_atm_consent_still_valid', static function ($valid, $receipt) use (&$allowed) {
    return $allowed && $receipt === 'fixture-receipt';
}, 10, 2);
add_filter('pre_wp_mail', '__return_true');
require_once dirname(__DIR__) . '/modules/advanced-tracking-manager/module.php';
require_once WUTM_ATM_DIR . 'includes/class-observability.php';
require_once WUTM_ATM_DIR . 'includes/class-event-admin.php';
$requests = [];
$next_http = null;
add_filter('pre_http_request', static function ($response, $args, $url) use (&$requests,&$next_http) {
    if (strpos($url, 'https://graph.facebook.com/') !== 0) return $response;
    if ($next_http !== null) return $next_http;
    $requests[] = [$url, $args, json_decode($args['body'], true)];
    if (count($requests) === 1) return new WP_Error('fixture_timeout');
    return ['body'=>'{"events_received":1}','response'=>['code'=>200]];
}, 10, 3);

$orders = [];
$product = null;
$variable = null;
$variation = null;
global $wpdb;
$table = WUTM_ATM_Queue::table();
$read = static function ($id) use ($wpdb, $table) {
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE event_id=%s", $id));
};
try {
    WUTM_ATM_Queue::ensure();
    wutm_tracking_assert(WUTM_ATM_Events::consent() === [], 'Unconfirmed consent must default to denied.');
    wutm_tracking_assert(WUTM_ATM_Events::track('Lead') === null && !$requests, 'Denied request cannot prepare or send Lead.');
    $lease = WUTM_ATM_Queue::lock();
    wutm_tracking_assert($lease !== '' && WUTM_ATM_Queue::work('manual')['result'] === 'locked', 'Competing worker cannot proceed.');
    WUTM_ATM_Queue::unlock($lease);
    $expired_lease = (time() - 30) . ':previous-worker';
    $wpdb->insert($wpdb->options, ['option_name'=>WUTM_ATM_Queue::LOCK,'option_value'=>$expired_lease,'autoload'=>'no']);
    $lease = WUTM_ATM_Queue::lock();
    wutm_tracking_assert($lease !== '', 'Expired worker lease can be recovered.');
    WUTM_ATM_Queue::unlock($expired_lease);
    wutm_tracking_assert(WUTM_ATM_Queue::lock() === '', 'Stale worker cannot release the new lease.');
    WUTM_ATM_Queue::unlock($lease);
    wutm_tracking_assert(get_option('atm_google_ads_id') === 'AW-123456/fixture', 'Legacy option preserved.');
    $allowed = true;
    update_option('atm_fb_capi_token', '');
    wutm_tracking_assert(!WUTM_ATM_Events::server_active() && WUTM_ATM_Events::browser_active(), 'Missing token disables CAPI only.');
    update_option('atm_fb_capi_token', 'fixture-only');
    update_option('atm_fb_pixel_id', '');
    wutm_tracking_assert(!WUTM_ATM_Events::server_active() && !WUTM_ATM_Events::browser_active(), 'Combined mode requires a Pixel.');
    update_option('atm_fb_pixel_id', '123456789');
    delete_option(WUTM_ATM_Observability::META_RESULTS);
    foreach (['connection','test'] as $diagnostic) {
        delete_transient('wutm_atm_meta_' . $diagnostic);
        $next_http = $diagnostic === 'connection' ?
            ['body'=>'{"error":{"code":100,"message":"fixture-only"}}','response'=>['code'=>400]] :
            ['body'=>'{"events_received":1}','response'=>['code'=>200]];
        WUTM_ATM_Event_Admin::check_meta($diagnostic, true);
    }
    $report = WUTM_ATM_Observability::report();
    wutm_tracking_assert(empty($report['meta']['connection']['confirmed']) && !empty($report['meta']['test']['confirmed']) &&
        $report['meta']['test']['current_config'] && strpos(wp_json_encode($report), 'fixture-only') === false &&
        strpos(wp_json_encode($report), 'TEST123') === false, 'Read and write outcomes stay independent and export excludes secrets.');
    foreach ([200=>'retry',400=>'failed',401=>'failed',403=>'failed',429=>'retry',500=>'retry'] as $code=>$expected) {
        $next_http = ['body'=>'{}','response'=>['code'=>$code]];
        $fixture_id = WUTM_ATM_Events::track('Lead', [], ['key'=>'http-'.$code,'server_only'=>true,
            'user'=>['email'=>'fixture@example.invalid','user_agent'=>'Test']]);
        $ran = WUTM_ATM_Queue::work('manual');
        $row = $read($fixture_id);
        wutm_tracking_assert($ran['processed'] >= 1 && $row && $row->status === $expected && (int) $row->http_code === $code &&
            $row->attempted > 0, 'Worker records HTTP ' . $code . ' (' . ($row->status ?? 'missing') . '/' . ($row->reason ?? '') .
                ', run ' . $ran['result'] . ', attempts ' . ($row->attempted ?? 0) . ')');
        $wpdb->delete($table, ['event_id'=>$fixture_id]);
    }
    $next_http = ['body'=>'{}','response'=>['code'=>500]];
    $retention_id = WUTM_ATM_Events::track('Lead', [], ['key'=>'retention','server_only'=>true,
        'user'=>['email'=>'fixture@example.invalid','user_agent'=>'Test']]);
    $wpdb->update($table, ['event_time'=>time()-3 * DAY_IN_SECONDS], ['event_id'=>$retention_id]);
    WUTM_ATM_Queue::work();
    wutm_tracking_assert($read($retention_id)->reason === 'expired', 'Expired work is closed without delivery.');
    $wpdb->update($table, ['updated'=>time()-61 * DAY_IN_SECONDS], ['event_id'=>$retention_id]);
    WUTM_ATM_Queue::work();
    wutm_tracking_assert($read($retention_id) === null, 'Retention removes old log rows.');
    $settings = WUTM_ATM_Events::settings();
    update_option(WUTM_ATM_Events::OPTION, array_replace($settings, ['enabled'=>false]));
    wutm_tracking_assert(WUTM_ATM_Events::track('Lead') === null, 'Disabled events never create new rows.');
    update_option(WUTM_ATM_Events::OPTION, $settings);
    $next_http = null;
    $future = time() + 10;
    wp_clear_scheduled_hook(WUTM_ATM_Queue::HOOK);
    wp_schedule_single_event($future, WUTM_ATM_Queue::HOOK);
    wutm_tracking_assert(WUTM_ATM_Observability::queue($future - 10)['status'] === 'normal', 'Normal scheduling delay is healthy.');
    wutm_tracking_assert(WUTM_ATM_Observability::queue($future + 91)['status'] === 'overdue', 'Old scheduled event is distinguished.');
    if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON', true);
    wutm_tracking_assert(WUTM_ATM_Observability::queue()['spawn_disabled'] === (bool) DISABLE_WP_CRON, 'WP-Cron configuration is explicit.');
    wp_clear_scheduled_hook(WUTM_ATM_Queue::HOOK);
    if (!class_exists('WooCommerce')) {
        wutm_tracking_assert(!class_exists('WUTM_ATM_Woo_Events'), 'WooCommerce adapter must stay unloaded.');
        echo "Native tracking without WooCommerce passed.\n";
        return;
    }
    // Production Purchase and simulations have separate identifiers.
    $options['wutm_atm_events']['test_code'] = '';
    update_option(WUTM_ATM_Events::OPTION, $options['wutm_atm_events']);
    $product = new WC_Product_Simple();
    $product->set_name('Tracking fixture');
    $product->set_regular_price('25');
    $product->set_virtual(true);
    $product->save();
    $variable = new WC_Product_Variable();
    $variable->set_name('Tracking variable fixture');
    $variable->save();
    $variation = new WC_Product_Variation();
    $variation->set_parent_id($variable->get_id());
    $variation->set_regular_price('12.50');
    $variation->save();
    WC()->session = new WC_Session_Handler();
    $cart_settings = $options['wutm_atm_events'];
    $cart_settings['mode'] = 'browser';
    update_option(WUTM_ATM_Events::OPTION, $cart_settings);
    do_action('woocommerce_add_to_cart', 'fixture-key', $variable->get_id(), 2, $variation->get_id(), [], []);
    update_option(WUTM_ATM_Events::OPTION, $options['wutm_atm_events']);
    $cart_events = array_values((array) WC()->session->get(WUTM_ATM_Woo_Events::CART));
    wutm_tracking_assert(count($cart_events) === 1 && $cart_events[0]['event']['data']['content_ids'] === [(string) $variation->get_id()], 'Cart hook prepares the variation and quantity for AJAX browser delivery.');
    wutm_tracking_assert($cart_events[0]['event']['data']['num_items'] === 2, 'Cart hook retains purchased quantity.');
    $make_order = static function ($status = 'pending') use (&$orders, $product) {
        $order = wc_create_order(['status'=>$status]);
        $orders[] = $order;
        $item_id = $order->add_product($product, 1);
        $item = $order->get_item($item_id);
        $item->set_total('19.50');
        $item->save();
        $order->set_currency('EUR');
        $order->set_total('19.50');
        $order->set_payment_method('bacs');
        $order->set_billing_email('fixture@example.test');
        $order->set_customer_user_agent('Tracking fixture');
        $order->save();
        return $order;
    };
    $order = $make_order();
    $id = WUTM_ATM_Events::event_id('Purchase', 'order:' . $order->get_id());
    do_action('woocommerce_checkout_create_order', $order, []);
    $order->save();
    WUTM_ATM_Woo_Events::purchase($order->get_id());
    wutm_tracking_assert(!$read($id), 'Pending order never queues.');
    foreach (['failed','cancelled'] as $status) {
        $invalid = $make_order($status);
        WUTM_ATM_Woo_Events::purchase($invalid->get_id());
        wutm_tracking_assert(!$read(WUTM_ATM_Events::event_id('Purchase', 'order:' . $invalid->get_id())), 'Invalid order never queues.');
    }
    $order->payment_complete('fixture-payment');
    WUTM_ATM_Woo_Events::purchase($order->get_id());
    wutm_tracking_assert($read($id)->status === 'pending' && !$requests, 'Payment schedules one deferred event.');
    WUTM_ATM_Purchase::locked($order->get_id(), static function (WC_Order $order) {
        WUTM_ATM_Purchase::emit($order, false);
    });
    wutm_tracking_assert((int) $read($id)->browser === 1, 'Browser and server use the same order row.');
    $inspected = WUTM_ATM_Observability::order($order->get_id());
    wutm_tracking_assert($inspected['Purchase event_id'] === $id && $inspected['訂單總額'] === 'EUR 19.50' &&
        (int) $read($id)->tries === 0, 'Order inspection uses WC CRUD and makes no delivery attempt.');
    WUTM_ATM_Queue::work();
    $retry = $read($id);
    wutm_tracking_assert($retry->status === 'retry' && (int) $retry->tries === 1, 'Transport failure is retryable. Actual: ' . $retry->status . '/' . $retry->reason);
    $wpdb->update($table, ['due'=>0], ['event_id'=>$id]);
    WUTM_ATM_Queue::work();
    $data = $requests[1][2]['data'][0] ?? [];
    wutm_tracking_assert($read($id)->status === 'sent' && $read($id)->payload === '', 'Acknowledgement clears server payload.');
    wutm_tracking_assert($data['event_id'] === $id && $requests[0][2] === $requests[1][2], 'Retry preserves the production payload and ID.');
    wutm_tracking_assert($data['custom_data']['value'] === 19.5 && $data['custom_data']['currency'] === 'EUR', 'Actual discounted order amount and currency.');
    wutm_tracking_assert($data['user_data']['em'] === [hash('sha256','fixture@example.test')] && strpos(wp_json_encode($data), 'fixture@example.test') === false, 'Email normalized and hashed.');
    WUTM_ATM_Woo_Events::purchase($order->get_id());
    WUTM_ATM_Queue::work();
    wutm_tracking_assert(count($requests) === 2, 'Repeated payment callback cannot resend.');
    $blocks = $make_order();
    do_action('woocommerce_store_api_checkout_order_processed', $blocks);
    $blocks->payment_complete('fixture-blocks-payment');
    $blocks_id = WUTM_ATM_Events::event_id('Purchase', 'order:' . $blocks->get_id());
    wutm_tracking_assert($read($blocks_id)->status === 'pending', 'Blocks order captured consent.');
    $allowed = false;
    WUTM_ATM_Queue::work();
    wutm_tracking_assert($read($blocks_id)->status === 'consent_blocked' && $read($blocks_id)->payload === '' && count($requests) === 2, 'Withdrawal prevents pending delivery.');
    echo "Native tracking checks passed (" . (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'posts') . ").\n";
} finally {
    foreach ($orders as $order) $order->delete(true);
    if ($variation) $variation->delete(true);
    if ($variable) $variable->delete(true);
    if ($product) $product->delete(true);
    $wpdb->query("DROP TABLE IF EXISTS $table");
    delete_option(WUTM_ATM_Queue::SCHEMA);
    wp_clear_scheduled_hook(WUTM_ATM_Queue::HOOK);
    wp_clear_scheduled_hook(WUTM_ATM_Queue::SCHEDULE);
    delete_option(WUTM_ATM_Observability::META_RESULTS);
    delete_option(WUTM_ATM_Queue::LAST_RUN);
    delete_option(WUTM_ATM_Queue::LAST_CRON);
    delete_option(WUTM_ATM_Queue::CAPACITY_CHECK);
    delete_transient('wutm_atm_meta_connection');
    delete_transient('wutm_atm_meta_test');
    foreach ($previous as $name => $value) {
        if ($value === false) delete_option($name);
        else update_option($name, $value);
    }
}
