<?php
if (!defined('WP_CLI') || getenv('WUTM_MEDIA_INTEGRATION') !== '1' || DB_NAME !== 'wci_fixture' || !class_exists('WooCommerce')) {
    throw new RuntimeException('Disposable WooCommerce fixture only.');
}

$check = static function ($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$native_tutor = function_exists('tutor');
if (!$native_tutor) {
    function tutor() { return (object) ['course_post_type'=>'courses']; }
    function tutor_utils() {
        return new class {
            public function get_option($key) { return get_option('tutor_option')[$key] ?? ''; }
            public function get_course_product_id($id) { return (int) get_post_meta($id, '_tutor_course_product_id', true); }
            public function is_course_purchasable($id) { return (bool) $this->get_course_product_id($id); }
        };
    }
    register_post_type('courses', ['public'=>true]);
}
register_post_type('atm_fixture_page', ['public'=>true]);
$course_type = tutor()->course_post_type;

$options = [
    'wutm_atm_events'=>['enabled'=>true,'woo'=>true,'mode'=>'both','events'=>['PageView','ViewContent']],
    'wutm_atm_privacy'=>['enabled'=>true,'first_visit'=>'opt_in','policy'=>'content-fixture'],
    'atm_fb_pixel_id'=>'123456789','atm_fb_pixel_enabled'=>1,'atm_fb_capi_enabled'=>1,'atm_fb_capi_token'=>'fixture-only',
    'atm_exclude_admin'=>0,'woocommerce_currency'=>'USD','tutor_option'=>['monetize_by'=>'wc'],
];
$previous = [];
foreach ($options as $name=>$value) {
    $previous[$name] = get_option($name);
    update_option($name, $value);
}
require_once dirname(__DIR__) . '/modules/advanced-tracking-manager/module.php';

$requests = [];
add_filter('pre_http_request', static function ($value, $args, $url) use (&$requests) {
    if (strpos($url, 'https://graph.facebook.com/') !== 0) return $value;
    $requests[] = json_decode($args['body'], true)['data'][0];
    return ['body'=>'{"events_received":1}','response'=>['code'=>200]];
}, 10, 3);
$posts = [];
$products = [];
$current_user = get_current_user_id();
$old_query = $GLOBALS['wp_query'];
$old_main = $GLOBALS['wp_the_query'];
$old_server = array_intersect_key($_SERVER, array_flip(['REQUEST_URI','HTTP_USER_AGENT']));
$old_cookies = $_COOKIE;
$policy = 'content-fixture';
$token = bin2hex(random_bytes(32));
global $wpdb;
$table = WUTM_ATM_Queue::table();
$lookup = static function ($id) use ($wpdb, $table) {
    return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE event_id=%s", $id));
};
$make_post = static function (string $type, string $title) use (&$posts): int {
    $id = wp_insert_post(['post_type'=>$type,'post_title'=>$title,'post_status'=>'publish']);
    $posts[] = $id;
    return $id;
};
$use_page = static function ($id, $type, $url): array {
    $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query($type === 'page' ? ['page_id'=>$id] : ['p'=>$id,'post_type'=>$type]);
    $_SERVER['REQUEST_URI'] = $url;
    return WUTM_ATM_Content::page();
};

try {
    WUTM_ATM_Queue::ensure();
    $check(WUTM_ATM_Consent_Store::install(), 'The consent store is ready.');
    $product = new WC_Product_Simple();
    $product->set_name('Hidden catalog course product');
    $product->set_regular_price('39');
    $product->set_sale_price('25.50');
    $product->set_catalog_visibility('hidden');
    $product->save();
    $products[] = $product;

    $variable = new WC_Product_Variable();
    $variable->set_name('Variable example');
    $attribute = new WC_Product_Attribute();
    $attribute->set_name('Size');
    $attribute->set_options(['Small','Large']);
    $attribute->set_variation(true);
    $attribute->set_visible(true);
    $variable->set_attributes([$attribute]);
    $variable->set_default_attributes(['size'=>'Small']);
    $variable->save();
    $products[] = $variable;
    $variations = [];
    foreach (['Small'=>'30','Large'=>'45'] as $size=>$price) {
        $variation = new WC_Product_Variation();
        $variation->set_parent_id($variable->get_id());
        $variation->set_attributes(['size'=>$size]);
        $variation->set_regular_price($price);
        $variation->save();
        $products[] = $variation;
        $variations[$size] = $variation->get_id();
    }
    WC_Product_Variable::sync($variable);
    $simple = $use_page($product->get_id(), 'product', '/fixture-product/');
    $check($simple['kind'] === 'product' && $simple['product'] === $product->get_id(), 'A published WooCommerce product resolves.');
    $default = $use_page($variable->get_id(), 'product', '/fixture-variable/');
    $check($default['product'] === $variations['Small'], 'The default displayed variation resolves.');
    $_GET['attribute_size'] = 'Large';
    $selected = $use_page($variable->get_id(), 'product', '/fixture-variable/?attribute_size=Large');
    $check($selected['product'] === $variations['Large'] && $selected['url'] === home_url('/fixture-variable/'), 'A selected variation uses its own ID and clean source URL.');
    unset($_GET['attribute_size']);

    $course = $make_post($course_type, 'WooCommerce course fixture');
    update_post_meta($course, '_tutor_course_product_id', $product->get_id());
    update_post_meta($course, '_tutor_course_price_type', 'paid');
    $course_page = $use_page($course, $course_type, '/fixture-course/?key=wc_order_private&email=guest@example.test');
    $check($course_page === ['kind'=>'product','product'=>$product->get_id(),'url'=>home_url('/fixture-course/')],
        'A paid Tutor course resolves its WooCommerce mapping and retains the course URL.');
    update_option('tutor_option', ['monetize_by'=>'tutor']);
    $check($use_page($course, $course_type, '/fixture-course/')['kind'] === 'page', 'Other Tutor monetization does not reuse an old WooCommerce mapping.');
    update_option('tutor_option', $options['tutor_option']);
    $mapped = $make_post('atm_fixture_page', 'Mapped public page');
    add_filter('wutm_atm_content_product_id', static function ($default, WP_Post $post) use ($mapped, $variations) {
        return $post->ID === $mapped ? $variations['Large'] : $default;
    }, 10, 2);
    $check($use_page($mapped, 'atm_fixture_page', '/fixture-custom/')['product'] === $variations['Large'], 'A custom content type can resolve a variation.');
    $plain = $make_post('page', 'Unmapped public page');
    $check($use_page($plain, 'page', '/fixture-unmapped/')['product'] === 0, 'Unmapped pages do not emit ViewContent.');
    add_filter('wutm_atm_content_product_id', static function ($default, WP_Post $post) use ($plain, $product) {
        return $post->ID === $plain ? $product->get_id() : $default;
    }, 20, 2);
    foreach (['woocommerce_cart_page_id','woocommerce_myaccount_page_id'] as $option) {
        $before = get_option($option);
        update_option($option, $plain);
        try {
            $check($use_page($plain, 'page', '/fixture-protected/')['product'] === 0, 'Transactional pages bypass product resolution.');
        } finally {
            if ($before === false) delete_option($option); else update_option($option, $before);
        }
    }
    $GLOBALS['wp_query'] = $GLOBALS['wp_the_query'] = new WP_Query(['taxonomy'=>'product_cat','term'=>'uncategorized']);
    $check(WUTM_ATM_Content::page()['product'] === 0, 'A product taxonomy archive is not a product view.');

    // Reuse exactly the signed descriptor emitted into cacheable public HTML.
    $use_page($course, $course_type, '/fixture-course/?key=wc_order_private&email=guest@example.test');
    $render = new ReflectionMethod(WUTM_ATM_Consent::class, 'descriptor');
    $render->setAccessible(true);
    $descriptor = $render->invoke(null);
    $visit = new ReflectionMethod(WUTM_ATM_Consent::class, 'visit');
    $visit->setAccessible(true);
    $packet = ['page'=>$descriptor,'visit'=>str_repeat('a',32)];
    $cookie = WUTM_ATM_Consent::cookie_name();
    $_COOKIE[$cookie] = $token;
    unset($_COOKIE['_fbp'], $_COOKIE['_fbc']);
    $_SERVER['HTTP_USER_AGENT'] = 'Content fixture UA';
    // Emulate the replay from a cached HTML page with a guest making a new consent choice.
    wp_set_current_user(0);
    $check(WUTM_ATM_Events::consent() === [], 'New visitors do not inherit consent from the descriptor.');
    WUTM_ATM_Consent_Store::save($token, $policy, false, true, time()+HOUR_IN_SECONDS);
    WUTM_ATM_Consent::reset_cache();
    $events = array_column($visit->invoke(null, $packet, $token), null, 'name');
    $view = $events['ViewContent'] ?? [];
    $id = $view['id'] ?? '';
    $check(count($events) === 2 && $id && $view['data']['content_ids'] === [(string) $product->get_id()] &&
        $view['data']['content_name'] === $product->get_name() && $view['data']['content_type'] === 'product' &&
        $view['data']['value'] === 25.5 && $view['data']['currency'] === 'USD' && $lookup($id)->reason === 'matching_data_missing',
        'Browser ViewContent uses actual product name, type, sale price and currency. The initial AJAX request lacks the Pixel cookie.');
    $_COOKIE['_fbp'] = 'fb.1.1712345678901.1234567890';
    $forged = $packet;
    $forged['page']['product'] = $variations['Large'];
    $visit->invoke(null, $forged, $token, false, true);
    $check($lookup($id)->status === 'failed', 'Changing the signed product cannot repair an event.');
    $premature = array_replace($packet, ['visit'=>str_repeat('b',32)]);
    $visit->invoke(null, $premature, $token, false, true);
    $check((int) $wpdb->get_var("SELECT COUNT(*) FROM $table") === 2, 'Matching retry cannot create rows for visits that were never sent.');
    $check($visit->invoke(null, $packet, $token, false, true) === [] && $lookup($id)->status === 'pending',
        'A real browser ID promotes the same existing event without another Browser payload.');
    $visit->invoke(null, $packet, $token, false, true);
    WUTM_ATM_Queue::work();
    $deliveries = array_values(array_filter($requests, static function ($event) use ($id) { return $event['event_id'] === $id; }));
    $check(count($deliveries) === 1 && $deliveries[0]['custom_data'] === $view['data'] &&
        $deliveries[0]['event_source_url'] === home_url('/fixture-course/') && $deliveries[0]['user_data']['fbp'] === $_COOKIE['_fbp'],
        'The queued server event keeps the Browser event ID, product data, verified course URL and the supplied fixture cookie.');

    $subsequent = array_replace($packet, ['visit'=>str_repeat('c',32)]);
    $returning = array_column($visit->invoke(null, $subsequent, $token), null, 'name')['ViewContent'];
    $check($lookup($returning['id'])->status === 'pending', 'A returning visitor with consent and a browser cookie queues directly.');
    $request_count = count($requests);
    WUTM_ATM_Consent_Store::save($token, $policy, false, false, time()+HOUR_IN_SECONDS);
    WUTM_ATM_Consent::reset_cache();
    $check(!WUTM_ATM_Consent::marketing(), 'Revocation removes the valid marketing receipt.');
    WUTM_ATM_Queue::work();
    $check($lookup($returning['id'])->status === 'consent_blocked' && count($requests) === $request_count,
        'Revocation stops pending CAPI requests.');
    echo 'Storefront ViewContent and matching checks passed (' . ($native_tutor ? 'native Tutor LMS' : 'Tutor API fixture') . ").\n";
} finally {
    wp_set_current_user($current_user);
    $GLOBALS['wp_query'] = $old_query;
    $GLOBALS['wp_the_query'] = $old_main;
    $_COOKIE = $old_cookies;
    unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_USER_AGENT']);
    $_SERVER += $old_server;
    foreach ($posts as $id) wp_delete_post($id, true);
    foreach (array_reverse($products) as $product) $product->delete(true);
    $wpdb->query("DROP TABLE IF EXISTS $table");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wutm_atm_consent");
    foreach ([WUTM_ATM_Queue::SCHEMA,WUTM_ATM_Queue::LAST_RUN,WUTM_ATM_Queue::LAST_CRON,WUTM_ATM_Queue::CAPACITY_CHECK,WUTM_ATM_Consent_Store::SCHEMA] as $key) delete_option($key);
    foreach ([WUTM_ATM_Queue::HOOK,WUTM_ATM_Queue::SCHEDULE,WUTM_ATM_Consent_Store::CRON] as $hook) wp_clear_scheduled_hook($hook);
    foreach ($previous as $key=>$value) {
        if ($value === false) delete_option($key);
        else update_option($key, $value);
    }
}
