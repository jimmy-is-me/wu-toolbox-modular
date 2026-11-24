<?php
if (!defined('WP_CLI') || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture') {
    throw new RuntimeException('Disposable WordPress fixture only.');
}

function wutm_privacy_assert($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$opts = [
    'wutm_atm_privacy'=>['enabled'=>true,'policy'=>'fixture-policy-1'],
    'wutm_atm_events'=>['enabled'=>true,'mode'=>'both','woo'=>true,'events'=>['Purchase','PageView'],'test_code'=>'TEST123'],
    'atm_ga4_enabled'=>1, 'atm_ga4_id'=>'G-FIXTURE', 'atm_google_ads_enabled'=>1, 'atm_google_ads_id'=>'AW-123456',
    'atm_gtm_enabled'=>1, 'atm_gtm_id'=>'GTM-FIXTURE',
    'atm_fb_pixel_id'=>'123456789', 'atm_fb_pixel_enabled'=>1, 'atm_fb_capi_enabled'=>1,
    'atm_fb_capi_token'=>'fixture-only', 'atm_exclude_admin'=>0,
];
$previous = [];
foreach ($opts as $name=>$value) {
    $previous[$name] = get_option($name);
    update_option($name, $value);
}
add_filter('wutm_atm_marketing_consent', '__return_true');
add_filter('wutm_atm_consent_still_valid', '__return_true');
require_once dirname(__DIR__) . '/modules/advanced-tracking-manager/module.php';
$requests = [];
add_filter('pre_wp_mail','__return_true');
add_filter('pre_http_request', static function ($result, $args, $url) use (&$requests) {
    if (strpos($url, 'https://graph.facebook.com/') !== 0) return $result;
    $requests[] = json_decode($args['body'], true);
    return ['body'=>'{"events_received":1}','response'=>['code'=>200]];
}, 10, 3);

global $wpdb;
$visitors = [str_repeat('a', 64), str_repeat('b', 64)];
$policy = $opts['wutm_atm_privacy']['policy'];
$until = time() + DAY_IN_SECONDS;
$cookie = WUTM_ATM_Consent::cookie_name();
$orders = [];
$consent_table = $wpdb->prefix . 'wutm_atm_consent';
$event_table = WUTM_ATM_Queue::table();
$set_visitor = static function ($token) use ($cookie) {
    if ($token) $_COOKIE[$cookie] = $token;
    else unset($_COOKIE[$cookie]);
    WUTM_ATM_Consent::reset_cache();
};
$receipt = static function () { return WUTM_ATM_Events::consent()['reference'] ?? ''; };
try {
    delete_option(WUTM_ATM_Consent::OPTION);
    wutm_privacy_assert(WUTM_ATM_Consent::settings()['first_visit'] === 'opt_in', 'New sites default to explicit consent.');
    update_option(WUTM_ATM_Consent::OPTION, $opts[WUTM_ATM_Consent::OPTION]);
    wutm_privacy_assert(WUTM_ATM_Consent_Store::install(), 'Consent table installed.');
    WUTM_ATM_Queue::ensure();
    wutm_privacy_assert(false === has_action('template_redirect', ['WUTM_ATM_Events','protect_cache']), 'Built-in consent leaves public pages cacheable.');
    wutm_privacy_assert(WUTM_ATM_Events::consent() === [], 'Unknown visitor denied even with external grant filter.');
    $state = new ReflectionMethod(WUTM_ATM_Consent::class, 'state');
    $state->setAccessible(true);
    foreach ($visitors as $token) wutm_privacy_assert(WUTM_ATM_Consent_Store::read($token, $policy) === null, 'Unsaved visitors have no record.');
    $default_token = str_repeat('c', 64);
    $set_visitor($default_token);
    update_option(WUTM_ATM_Consent::OPTION, $opts[WUTM_ATM_Consent::OPTION] + ['first_visit'=>'opt_in']);
    $opt_in = $state->invoke(null, $default_token);
    wutm_privacy_assert(!$opt_in['defaults'] && !$opt_in['tags']['ga4'] && !$opt_in['tags']['pixel'], 'Explicit opt-in keeps waiting for a choice.');
    update_option(WUTM_ATM_Consent::OPTION, $opts[WUTM_ATM_Consent::OPTION]);
    $initial = $state->invoke(null, $default_token);
    wutm_privacy_assert(!$initial['chosen'] && $initial['defaults'] && $initial['tags']['ga4'] && $initial['tags']['pixel'], 'Opt-out enables the configured browser tags while leaving the choice open.');
    wutm_privacy_assert($receipt() === '' && WUTM_ATM_Consent_Store::read($default_token, $policy) === null, 'Opt-out never invents a receipt.');
    $visit = new ReflectionMethod(WUTM_ATM_Consent::class, 'visit');
    $visit->setAccessible(true);
    $url = home_url('/');
    $proof = hash_hmac('sha256', "page|0|$url", wp_salt('nonce'));
    $events = $visit->invoke(null, ['page'=>['kind'=>'page','product'=>0,'url'=>$url,'proof'=>$proof], 'visit'=>str_repeat('d',32)], $default_token, true);
    wutm_privacy_assert(count($events) === 1 && $events[0]['name'] === 'PageView', 'Provisional visit starts browser collection.');
    WUTM_ATM_Queue::work();
    wutm_privacy_assert(!$requests, 'Provisional visit does not send server events.');
    WUTM_ATM_Consent_Store::save($default_token, $policy, false, false, $until);
    WUTM_ATM_Consent::reset_cache();
    $refused = $state->invoke(null, $default_token);
    wutm_privacy_assert($refused['chosen'] && !$refused['defaults'] && !$refused['tags']['ga4'] && !$refused['tags']['pixel'] &&
        !WUTM_ATM_Consent::allows_default_visit(), 'Stored rejection overrides future defaults.');
    // Expire the extra fixture row before the retention test.
    WUTM_ATM_Consent_Store::save($default_token, $policy, false, false, time()-5);
    update_option(WUTM_ATM_Consent::OPTION, $opts[WUTM_ATM_Consent::OPTION]);
    $set_visitor($visitors[0]);
    wutm_privacy_assert(WUTM_ATM_Consent_Store::save($visitors[0], $policy, true, false, $until), 'Analysis-only choice saved.');
    WUTM_ATM_Consent::reset_cache();
    $tags = $state->invoke(null,$visitors[0])['tags'];
    wutm_privacy_assert($tags['ga4'] === 'G-FIXTURE' && !$tags['ads'] && !$tags['gtm'] && !$tags['pixel'], 'Analytics must not start Ads, mixed GTM or Meta.');
    $set_visitor($visitors[1]);
    wutm_privacy_assert(WUTM_ATM_Consent_Store::save($visitors[1], $policy, false, true, $until), 'Marketing-only choice saved.');
    $tags = $state->invoke(null,$visitors[1])['tags'];
    $original = $receipt();
    wutm_privacy_assert($original && !$tags['ga4'] && $tags['ads'] && !$tags['gtm'] && $tags['pixel'], 'Marketing must not start GA4 or mixed GTM.');
    wutm_privacy_assert(!WUTM_ATM_Events::consent_valid(['granted'=>true,'reference'=>'external-receipt']), 'External receipt cannot bypass active built-in consent.');
    $set_visitor($visitors[0]);
    wutm_privacy_assert($receipt() === '', 'Separate browser did not inherit grant.');
    $set_visitor($visitors[1]);
    wutm_privacy_assert($receipt() === $original, 'Returning visitor retains grant.');
    WUTM_ATM_Consent_Store::save($visitors[1], $policy, true, true, $until);
    WUTM_ATM_Consent::reset_cache();
    wutm_privacy_assert($receipt() === $original, 'Updating analytics preserves an uninterrupted marketing receipt.');
    wutm_privacy_assert($state->invoke(null,$visitors[1])['tags']['gtm'] === 'GTM-FIXTURE', 'Mixed GTM requires both.');

    if (class_exists('WooCommerce')) {
        update_option(WUTM_ATM_Events::OPTION, array_replace(WUTM_ATM_Events::settings(), ['test_code'=>'']));
        $make_order = static function (bool $blocks) use (&$orders) {
            $order = wc_create_order(['status'=>'pending']);
            $orders[] = $order;
            $order->set_currency('EUR');
            $order->set_total('18.75');
            $order->set_billing_email('fixture@example.test');
            $order->set_customer_user_agent('Fixture Browser');
            $order->set_payment_method('bacs');
            $order->save();
            if ($blocks) do_action('woocommerce_store_api_checkout_order_processed', $order);
            else {
                do_action('woocommerce_checkout_create_order', $order, []);
                $order->save();
            }
            return $order;
        };
        $first = $make_order(true);
        $second = $make_order(false);
        wutm_privacy_assert($first->get_meta(WUTM_ATM_Woo_Events::META)['reference'] === $original, 'Blocks saved the server-verifiable receipt.');
        wutm_privacy_assert($second->get_meta(WUTM_ATM_Woo_Events::META)['reference'] === $original, 'Classic saved the same canonical receipt.');
        $set_visitor('');
        $first->payment_complete('async-first');
        WUTM_ATM_Queue::work();
        wutm_privacy_assert(count($requests) === 1 && $requests[0]['data'][0]['custom_data']['value'] === 18.75, 'Async paid event delivered using the stored consent without browser cookies.');
        $second->payment_complete('async-second');
    }
    WUTM_ATM_Consent_Store::save($visitors[1], $policy, false, false, $until);
    wutm_privacy_assert(!WUTM_ATM_Consent_Store::valid($original, $policy), 'Withdrawal immediately invalidates the old order receipt.');
    WUTM_ATM_Consent_Store::save($visitors[1], $policy, false, true, $until);
    $set_visitor($visitors[1]);
    wutm_privacy_assert($receipt() && $receipt() !== $original, 'New grant cannot resurrect an old order receipt.');
    if ($orders) {
        $set_visitor('');
        WUTM_ATM_Queue::work();
        $id = WUTM_ATM_Events::event_id('Purchase', 'order:' . $second->get_id());
        $row = $wpdb->get_row($wpdb->prepare("SELECT status,payload FROM $event_table WHERE event_id=%s",$id));
        wutm_privacy_assert($row->status === 'consent_blocked' && $row->payload === '' && count($requests) === 1, 'Cron revalidates before sending queued purchases and discards withdrawn data.');
    }
    wutm_privacy_assert(!WUTM_ATM_Consent_Store::read($visitors[1], 'fixture-policy-2'), 'Policy reset requires new consent.');
    WUTM_ATM_Consent_Store::save($visitors[0], $policy, true, true, time()-60);
    WUTM_ATM_Consent_Store::cleanup();
    $fixture_rows = $wpdb->get_col($wpdb->prepare("SELECT visitor FROM $consent_table WHERE visitor IN (%s,%s,%s)",
        hash('sha256',$visitors[0]), hash('sha256',$visitors[1]), hash('sha256',$default_token)));
    wutm_privacy_assert($fixture_rows === [hash('sha256',$visitors[1])], 'Expired fixture rows removed without affecting active visitors.');
    echo "Native Cookie consent checks passed.\n";
} finally {
    foreach ($orders as $order) $order->delete(true);
    $wpdb->query("DROP TABLE IF EXISTS $consent_table");
    $wpdb->query("DROP TABLE IF EXISTS $event_table");
    delete_option(WUTM_ATM_Consent_Store::SCHEMA);
    delete_option(WUTM_ATM_Queue::SCHEMA);
    wp_clear_scheduled_hook(WUTM_ATM_Consent_Store::CRON);
    wp_clear_scheduled_hook(WUTM_ATM_Queue::HOOK);
    wp_clear_scheduled_hook(WUTM_ATM_Queue::SCHEDULE);
    foreach ($previous as $name=>$value) {
        if ($value === false) delete_option($name);
        else update_option($name,$value);
    }
}
