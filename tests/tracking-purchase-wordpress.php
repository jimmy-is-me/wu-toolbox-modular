<?php
if (!defined('WP_CLI') || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture' || !class_exists('WooCommerce')) {
    throw new RuntimeException('Disposable WooCommerce fixture only.');
}

$expect = static function ($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$settings = [
    'enabled'=>true,'mode'=>'both','woo'=>true,'events'=>['PageView','ViewContent','Purchase','AddToCart','InitiateCheckout'],
    'purchase_mode'=>'standard','test_code'=>'',
];
$options = [
    'wutm_atm_events'=>$settings,'atm_fb_pixel_id'=>'123456789','atm_fb_pixel_enabled'=>1,'atm_fb_capi_enabled'=>1,
    'wutm_atm_privacy'=>['enabled'=>false],'atm_fb_capi_token'=>'fixture-only','atm_exclude_admin'=>0,
    'woocommerce_bacs_settings'=>['enabled'=>'yes'], 'woocommerce_cod_settings'=>['enabled'=>'yes'],
];
$previous = [];
foreach ($options as $name=>$value) {
    $previous[$name] = get_option($name);
    update_option($name, $value);
}
$set = static function (array $changes) use ($settings) {
    update_option('wutm_atm_events', array_replace($settings, $changes));
};

require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once dirname(__DIR__) . '/modules/advanced-tracking-manager/module.php';
foreach (['class-observability','class-event-admin','class-purchase-admin'] as $file) {
    require_once WUTM_ATM_DIR . 'includes/' . $file . '.php';
}
$consent = true;
add_filter('wutm_atm_consent_still_valid', static function ($value, $ref) use (&$consent) {
    return $consent && $ref === 'purchase-fixture';
}, 10, 2);
add_filter('wutm_atm_marketing_consent', static function () use (&$consent) {
    return $consent ? ['granted'=>true,'reference'=>'purchase-fixture'] : false;
});
$requests = [];
$answer = 200;
add_filter('pre_http_request', static function ($value, $args, $url) use (&$requests, &$answer) {
    if (strpos($url, 'https://graph.facebook.com/') !== 0) return $value;
    $requests[] = [$url,json_decode($args['body'], true)];
    return ['body'=>$answer === 200 ? '{"events_received":1}' : '{"error":{"code":100}}','response'=>['code'=>$answer]];
}, 10, 3);
$mail = 0;
add_filter('pre_wp_mail', static function () use (&$mail) { $mail++; return true; });
$payments = 0;
add_action('woocommerce_payment_complete', static function () use (&$payments) { $payments++; });
$orders = [];
$product = new WC_Product_Simple();
$product->set_name('Purchase fixture');
$product->set_regular_price('12.50');
$product->save();
$make = static function ($status = 'pending', $method = 'bacs', $captured = true) use ($product, &$orders) {
    $order = wc_create_order(['status'=>$status]);
    $orders[] = $order->get_id();
    $order->add_product($product, 2, ['subtotal'=>25,'total'=>20]);
    $shipping = new WC_Order_Item_Shipping();
    $shipping->set_method_title('Fixture shipping');
    $shipping->set_total('4.50');
    $order->add_item($shipping);
    $order->set_shipping_total('4.50');
    $order->set_discount_total('5.00');
    $order->set_currency('EUR');
    $order->set_total('24.50');
    $order->set_payment_method($method);
    $order->set_billing_email('fixture@example.test');
    $order->set_customer_user_agent('Tracking fixture');
    if ($captured) WUTM_ATM_Woo_Events::capture_checkout($order);
    $order->save();
    return $order;
};
$official = static function (WC_Order $order) {
    return WUTM_ATM_Purchase::channels($order)[0];
};

global $wpdb, $wp_meta_boxes;
$table = WUTM_ATM_Queue::table();
try {
    WUTM_ATM_Queue::ensure();
    $set(['events'=>['PageView']]);
    $expect(!WUTM_ATM_Purchase::active(), 'Pixel and PageView do not activate Purchase.');
    $set(['woo'=>false]);
    $expect(!WUTM_ATM_Purchase::active(), 'Disabled WooCommerce integration hides Purchase.');
    $set(['enabled'=>false]);
    $expect(!WUTM_ATM_Purchase::active(), 'Disabled event management hides Purchase.');
    $set([]);
    $order = $make();
    $expect(!$official($order), 'Pending order did not dispatch.');
    foreach (['shop_order','woocommerce_page_wc-orders'] as $screen) {
        WUTM_ATM_Purchase_Admin::metabox($screen);
        $expect(isset($wp_meta_boxes[$screen]['side']['default']['wutm-atm-purchase']), 'Order metabox registered for ' . $screen);
    }
    $order->payment_complete('fixture-valid-payment');
    $row = $official($order);
    $expect($row && $row->status === 'pending', 'Native WooCommerce payment completion queued Purchase.');
    WUTM_ATM_Purchase::locked($order->get_id(), static function (WC_Order $stored) {
        WUTM_ATM_Purchase::emit($stored, false);
    });
    WUTM_ATM_Queue::work();
    $expect($official($order)->status === 'sent' && (int) $official($order)->browser === 1, 'Browser and CAPI share the production event record.');
    $data = $requests[0][1]['data'][0];
    $expect($data['event_id'] === $row->event_id && $data['custom_data']['value'] === 24.5 &&
        $data['custom_data']['currency'] === 'EUR' && $data['custom_data']['num_items'] === 2 &&
        $data['event_source_url'] === WUTM_ATM_Events::source_url(wc_get_checkout_url()), 'Final amount with shipping and discount, currency, items and checkout source are verified.');
    $order->payment_complete('fixture-repeat');
    $order->update_status('completed');
    $order->save();
    $wpdb->update($table, ['updated'=>time()-61*DAY_IN_SECONDS], ['event_id'=>$row->event_id]);
    WUTM_ATM_Queue::work();
    $expect($official($order)->event_id === $row->event_id && count($requests) === 1 &&
        WUTM_ATM_Purchase::act($order->get_id(), 'send') === 'existing', 'Stable ID survives date-based log cleanup, callback replays and order saves.');

    $cod = $make('pending', 'cod');
    $cod->update_status('processing');
    $expect(!WUTM_ATM_Woo_Events::paid(wc_get_order($cod->get_id())) && !$official($cod), 'Processing and the date WooCommerce inferred from its status do not prove COD collection.');

    $set(['purchase_mode'=>'manual']);
    $unpaid = $make('on-hold');
    ob_start();
    WUTM_ATM_Purchase_Admin::render($unpaid);
    $box = ob_get_clean();
    $expect(strpos($box, '確認收款並送出購買事件') !== false && strpos($box, 'required') !== false, 'Manual order view requires explicit acknowledgement.');
    $before = $unpaid->get_date_paid();
    $lease = WUTM_ATM_Queue::lock('wutm_atm_purchase_lease_' . $unpaid->get_id());
    $expect(WUTM_ATM_Purchase::act($unpaid->get_id(), 'confirm') === 'busy', 'Competing order action cannot reserve the same Purchase.');
    WUTM_ATM_Queue::unlock($lease, 'wutm_atm_purchase_lease_' . $unpaid->get_id());
    $expect(WUTM_ATM_Purchase::act($unpaid->get_id(), 'confirm') === 'queued', 'Manual confirmation queues via the order adapter.');
    $expect(WUTM_ATM_Purchase::act($unpaid->get_id(), 'confirm') === 'unavailable', 'Manual confirmation is single-use for the same event.');
    $unpaid = wc_get_order($unpaid->get_id());
    $evidence = $unpaid->get_meta(WUTM_ATM_Purchase::CONFIRMATION);
    $expect($unpaid->get_date_paid() == $before && $unpaid->has_status('on-hold') && !$unpaid->get_transaction_id() &&
        $evidence['source'] === 'manual' && $evidence['user_id'] === get_current_user_id() && $evidence['event_id'] === $official($unpaid)->event_id,
        'Manual evidence is auditable via WC CRUD and leaves payment data untouched.');
    $answer = 400;
    WUTM_ATM_Queue::work();
    $failed = $official($unpaid);
    $expect($failed->status === 'failed' && WUTM_ATM_Purchase::act($unpaid->get_id(),'retry') === 'queued', 'A failed attempt can be retried through the original ID.');
    $answer = 200;
    WUTM_ATM_Queue::work();
    $expect($official($unpaid)->status === 'sent' && $official($unpaid)->event_id === $failed->event_id, 'Retry preserves the existing production identifier.');
    $expect(strpos(WUTM_ATM_Observability::order($unpaid->get_id())['付款確認來源'], '管理員') !== false, 'Both admin views share payment evidence.');

    foreach (WC()->payment_gateways()->payment_gateways() as $id=>$gateway) {
        if (in_array($id, ['bacs','cod'], true)) $gateway->enabled = 'yes';
    }
    $set(['purchase_mode'=>'transition','purchase_gateways'=>['bacs'],'purchase_transitions'=>['on-hold:processing']]);
    $transfer = $make('on-hold');
    $transfer->update_status('processing');
    $payment = WUTM_ATM_Purchase::payment(wc_get_order($transfer->get_id()));
    $expect(($payment['source'] ?? '') === 'transition' && ($official($transfer)->status ?? '') === 'pending',
        'Configured transition should queue. Installed gateways: ' . implode(',', array_keys(WUTM_ATM_Purchase::gateways())) .
        ' · method ' . $transfer->get_payment_method() . ' · status ' . $transfer->get_status());
    $different = $make('on-hold', 'cod');
    $different->update_status('processing');
    $expect(!$official($different), 'An unmatched payment method remains excluded even on the configured transition.');
    $wrong = $make('on-hold');
    $wrong->update_status('completed');
    $expect(!$official($wrong), 'An unmatched status transition remains excluded.');
    $consent = false;
    $denied = $make('on-hold', 'bacs', false);
    $denied->update_status('processing');
    $expect(!$official($denied), 'A configured transition cannot bypass denied consent.');
    $set(['purchase_mode'=>'manual']);
    $expect(WUTM_ATM_Purchase::act($denied->get_id(), 'confirm') === 'consent', 'Manual confirmation cannot bypass denied consent.');
    WUTM_ATM_Queue::work();
    $expect($official($transfer)->status === 'consent_blocked', 'Queue revalidates consent after payment and before delivery.');
    $consent = true;

    $set(['test_code'=>'TEST_FIXTURE', 'purchase_test_dataset'=>'987654321']);
    $simulation = $make();
    $saved = [$simulation->get_status(),$simulation->get_date_paid(),$simulation->get_transaction_id(),$mail,$payments];
    $expect(WUTM_ATM_Purchase::act($simulation->get_id(),'test') === 'test' &&
        WUTM_ATM_Purchase::act($simulation->get_id(),'test') === 'existing', 'Explicit simulation creates one isolated test.');
    $test = WUTM_ATM_Purchase::channels($simulation)[1][0];
    $expect(!$official($simulation) && $test->test_mode == 1, 'Test cannot reserve a production event ID.');
    $set(['purchase_test_dataset'=>'987654321']);
    WUTM_ATM_Queue::work();
    [$target,$test_data] = end($requests);
    $expect(substr($target, -16) === '987654321/events' && ($test_data['test_event_code'] ?? '') === 'TEST_FIXTURE' &&
        $test_data['data'][0]['event_id'] === $test->event_id && !isset($test_data['data'][0]['user_data']['em']),
        'Configured test destination and code are preserved after removing the active test code, without sending the customer email.');
    $simulation = wc_get_order($simulation->get_id());
    $expect($saved === [$simulation->get_status(),$simulation->get_date_paid(),$simulation->get_transaction_id(),$mail,$payments],
        'Test hits no WooCommerce payment or email path and leaves the order unchanged.');
    $expect(WUTM_ATM_Purchase::act($simulation->get_id(),'test') === 'unavailable', 'Missing current test code prevents new simulations.');
    $simulation->payment_complete('fixture-later-production');
    $expect($official($simulation)->event_id !== $test->event_id, 'Later valid payment can create its own production event.');

    $url = home_url('/catalog/?page_id=32&key=wc_order_example&email=buyer@example.test#details');
    $expect(WUTM_ATM_Events::verified_url($url) === home_url('/catalog/?page_id=32') &&
        WUTM_ATM_Events::verified_url('https://outside.invalid/catalog/') === '' &&
        WUTM_ATM_Events::verified_url(home_url('/wp-json/wc/store/v1/cart')) === '', 'Source URL strips private data and rejects API/external sources.');
    $_SERVER['HTTP_REFERER'] = $url;
    $_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart/add-item';
    $expect(WUTM_ATM_Woo_Events::cart_source($product->get_id()) === home_url('/catalog/?page_id=32'), 'Cart source is the same-site page which invoked the REST endpoint.');
    unset($_SERVER['HTTP_REFERER'], $_SERVER['REQUEST_URI']);
    $set(['events'=>['PageView']]);
    $expect(!WUTM_ATM_Purchase::active(), 'Disabling Purchase also disables its action gate.');
    echo 'Purchase operations passed (' . (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'HPOS' : 'legacy') . ").\n";
} finally {
    foreach ($orders as $id) wc_get_order($id)->delete(true);
    $product->delete(true);
    $wpdb->query("DROP TABLE IF EXISTS $table");
    foreach ([WUTM_ATM_Queue::SCHEMA,WUTM_ATM_Queue::LAST_RUN,WUTM_ATM_Queue::LAST_CRON,WUTM_ATM_Queue::CAPACITY_CHECK] as $key) delete_option($key);
    foreach ([WUTM_ATM_Queue::HOOK,WUTM_ATM_Queue::SCHEDULE] as $hook) wp_clear_scheduled_hook($hook);
    foreach ($previous as $key=>$value) {
        if ($value === false) delete_option($key);
        else update_option($key,$value);
    }
}
