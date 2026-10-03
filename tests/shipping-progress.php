<?php
/** Shipping progress regression checks using actual module code and WC CRUD fixtures. */
define('ABSPATH', __DIR__ . '/');
define('WUTM_URL', '/');
define('WUTM_VERSION', '3.5.1');
$options = array(); $product_meta = array(); $live_products = array(); $orders = array();
$hooks = array(); $queries = array(); $order_reads = 0; $caps = array(); $user_id = 0; $received = false; $errors = array(); $styles = array(); $scripts = array();
class WooCommerce {}
class WP_Post { public $ID; public $post_content = ''; public function __construct($id) { $this->ID = $id; } }
class WP_Query {
    public $posts; public $max_num_pages = 2;
    public function __construct($args) { $GLOBALS['catalog_query'] = $args; $this->posts = array(new WP_Post(5), new WP_Post(7)); }
}
class Test_Response extends RuntimeException {}
class WP_Error { private $code; private $message; public function __construct($code, $message) { $this->code = $code; $this->message = $message; } public function get_error_code() { return $this->code; } public function get_error_message() { return $this->message; } }
class WC_Admin_Meta_Boxes { public static function add_error($message) { $GLOBALS['errors'][] = $message; } }
class WC_Rate_Limiter { public static $blocked = false; public static $writes = 0; public static function retried_too_soon($key) { return self::$blocked; } public static function set_rate_limit($key, $seconds) { self::$writes++; } }
class WC_Product {
    private $id; public $meta; public $saved = 0;
    public function __construct($id) { $this->id = $id; $this->meta = $GLOBALS['product_meta'][$id] ?? array(); }
    public function get_id() { return $this->id; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save() { $GLOBALS['product_meta'][$this->id] = $this->meta; $this->saved++; }
}
class WC_Order_Item_Product {
    private $id; private $product; private $variation; private $name; public $meta = array(); public $saved = 0;
    public function __construct($id, $product, $variation = 0, $name = '冬季預購商品') { $this->id = $id; $this->product = $product; $this->variation = $variation; $this->name = $name; }
    public function get_product_id() { return $this->product; }
    public function get_variation_id() { return $this->variation; }
    public function get_name() { return $this->name; }
    public function get_quantity() { return 2; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function add_meta_data($key, $value, $unique = false) { $this->meta[$key] = $value; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function delete_meta_data($key) { unset($this->meta[$key]); }
    public function save() { $this->saved++; }
}
class WC_Order {
    public $items; public $status = 'processing'; public $saved = 0;
    public function __construct($items) { $this->items = $items; }
    public function get_items($type = '') { return $this->items; }
    public function get_id() { return 42; }
    public function get_order_number() { return 'WU-42'; }
    public function get_billing_email() { return 'customer@example.test'; }
    public function get_customer_id() { return 71; }
    public function get_order_key() { return 'secret_order_key'; }
    public function get_status() { return $this->status; }
    public function has_status($statuses) { return in_array($this->status, (array) $statuses, true); }
    public function get_billing_last_name() { return '陳'; }
    public function get_billing_first_name() { return '小明'; }
    public function get_formatted_billing_full_name() { return '陳小明'; }
    public function get_date_created() { return new DateTimeImmutable('2026-10-03'); }
    public function get_formatted_shipping_address() { return 'PRIVATE_ADDRESS'; }
    public function get_formatted_billing_address() { return 'PRIVATE_BILLING'; }
    public function get_shipping_method() { return '宅配'; }
    public function get_meta($key, $single = true) { return ''; }
}
function add_action($hook, $callback, ...$args) { $GLOBALS['hooks'][$hook][] = $callback; }
function add_filter($hook, $callback, ...$args) { add_action($hook, $callback); }
function add_shortcode($tag, $callback) { $GLOBALS['hooks']['shortcodes'][$tag] = $callback; }
function apply_filters($hook, $value) { return $hook === 'woocommerce_shortcode_order_tracking_order_id' && $value === 'WU-42' ? 42 : $value; }
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
function wp_parse_args($value, $defaults) { return array_merge($defaults, $value); }
function current_user_can($cap, ...$args) { return in_array($cap, $GLOBALS['caps'], true); }
function get_current_user_id() { return $GLOBALS['user_id']; }
function wp_verify_nonce($value, $action) { return $value === 'valid'; }
function wp_unslash($value) { return $value; }
function wp_hash($value) { return hash('sha256', $value); }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function current_time($format) { return $format === 'mysql' ? '2026-10-03 15:30:00' : '2026/10/03'; }
function get_post_meta($id, $key, $single = true) { return $GLOBALS['product_meta'][$id][$key] ?? ''; }
function get_posts($args) {
    $GLOBALS['queries'][] = $args;
    return array_map(static function($id) { return new WP_Post($id); }, array_values(array_intersect($args['include'], $GLOBALS['live_products'])));
}
function wc_get_order($id) { $GLOBALS['order_reads']++; return $GLOBALS['orders'][$id] ?? false; }
function wc_get_product($id) { return in_array($id, $GLOBALS['live_products'], true) ? new WC_Product($id) : false; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_textarea($value) { return esc_html($value); }
function esc_url($value) { return esc_attr($value); }
function esc_url_raw($value) { return $value; }
function selected($actual, $expected, $echo = true) { $out = $actual === $expected ? 'selected' : ''; if ($echo) echo $out; return $out; }
function checked($actual, $expected = true, $echo = true) { $out = (bool) $actual === (bool) $expected ? 'checked' : ''; if ($echo) echo $out; return $out; }
function wp_enqueue_style($id, ...$args) { $GLOBALS['styles'][] = $id; }
function wp_enqueue_script($id, ...$args) { $GLOBALS['scripts'][] = $id; }
function did_action($hook) { return 0; }
function doing_action($hook) { return false; }
function wp_style_is(...$args) { return false; }
function wp_print_styles(...$args) {}
function is_order_received_page() { return $GLOBALS['received']; }
function wc_get_order_status_name($status) { return array('processing' => '處理中', 'cancelled' => '已取消')[$status] ?? $status; }
function wp_nonce_field($action, $name = '_wpnonce', $referer = true) { echo '<input type="hidden" name="' . esc_attr($name) . '" value="valid">'; }
function get_post_type($id) { return $id === 100 ? 'page' : 'product'; }
function get_post_status($id) { return $id === 101 ? 'draft' : 'publish'; }
function get_the_title($post) { return '預購商品'; }
function get_edit_post_link($id) { return '/wp-admin/post.php?post=' . $id; }
function settings_fields($group) { echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '">'; }
function submit_button($label, ...$args) { echo '<button class="button">' . esc_html($label) . '</button>'; }
function disabled($value, $compare = true, $echo = true) { $out = (bool) $value === (bool) $compare ? 'disabled' : ''; if ($echo) echo $out; return $out; }
function wp_dropdown_pages($args) { echo '<select name="' . esc_attr($args['name']) . '"><option value="100">出貨進度查詢</option></select>'; }
function admin_url($path) { return '/wp-admin/' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function paginate_links($args) { check(strpos($args['base'], '%#%') !== false, 'Pagination placeholder is not URL-encoded'); return '<a href="' . esc_attr(str_replace('%#%', '2', $args['base'])) . '">2</a>'; }
function wp_die($message) { throw new Test_Response($message); }
function check_admin_referer(...$args) { if (empty($GLOBALS['valid_admin_nonce'])) throw new Test_Response('nonce'); }
function wp_insert_post($data, $error = false) { $GLOBALS['created_pages'][] = $data; return 100; }
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; }
function wp_safe_redirect($url) { throw new Test_Response('redirect:' . $url); }
function get_permalink($id) { return '/shipping-progress/'; }
function get_page_uri($id) { return 'my-account'; }
function get_bloginfo($key) { return '測試商店'; }
function home_url($path) { return 'https://example.test' . $path; }
function wp_kses_post($value) { return $value; }
function wp_strip_all_tags($value) { return strip_tags($value); }
function wc_format_datetime($date, $format) { return $date->format($format); }
function get_current_screen() { return (object) ($GLOBALS['screen'] ?? array('id' => 'dashboard')); }
function wc_get_page_screen_id($id) { return 'woocommerce_page_wc-orders'; }
function is_page($id) { return false; }
function has_shortcode($content, $tag) { return strpos($content, '[' . $tag) !== false; }
function is_account_page() { return false; }
function is_wc_endpoint_url($endpoint) { return false; }

require dirname(__DIR__) . '/modules/shipping-notification-email/module.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function plan($from = '2026-11-10', $to = '2026-11-15') {
    $arrival = new DateTimeImmutable($to);
    return array('ship_from' => $from, 'ship_to' => $to, 'arrival_from' => $arrival->modify('+1 day')->format('Y-m-d'), 'arrival_to' => $arrival->modify('+3 days')->format('Y-m-d'), 'status' => 'preparing', 'message' => '預購商品分批出貨', 'updated_at' => '2026-10-03 15:30:00');
}
$product_meta[5]['_wutm_shipping_schedule'] = plan(); $live_products = array(5, 6, 7);
$item = new WC_Order_Item_Product(1, 5);
$variant = new WC_Order_Item_Product(2, 5, 6, '預購商品－米色');
$order = new WC_Order(array(1 => $item, 2 => $variant)); $orders[42] = $order;
$progress = new WUTM_Shipping_Progress();
$rows = $progress->order_plans($order);
check(count($queries) === 1 && count($queries[0]['include']) === 2 && $queries[0]['no_found_rows'] && $queries[0]['update_post_meta_cache'], 'One bounded batch loads only order products and primes metadata');
check($rows[1]['plan']['ship_from'] === '2026-11-10' && $rows[2]['source'] === 'product', 'Parent product estimates inherited by variation');
$product_meta[5]['_wutm_shipping_schedule'] = plan('2026-11-20', '2026-11-25');
$fresh = new WUTM_Shipping_Progress();
check($fresh->order_plans($order)[1]['plan']['ship_from'] === '2026-11-20', 'Existing order gets updated product dates on the next request');
$product_meta[6]['_wutm_shipping_schedule'] = plan('2026-12-01', '2026-12-05');
check((new WUTM_Shipping_Progress())->item_plan($variant)['source'] === 'variation', 'Variation-specific date overrides parent');
$item->meta['_wutm_shipping_schedule_override'] = plan('2026-11-01', '2026-11-05');
$item->meta['_wutm_shipping_schedule_override']['status'] = 'shipped';
check((new WUTM_Shipping_Progress())->item_plan($item)['plan']['ship_from'] === '2026-11-01', 'Order batch override is stable after product changes');
unset($item->meta['_wutm_shipping_schedule_override']);
$gone = new WC_Order_Item_Product(3, 999);
$gone->meta['_wutm_shipping_schedule_snapshot'] = plan();
check((new WUTM_Shipping_Progress())->item_plan($gone)['source'] === 'snapshot', 'Deleted products retain purchase-time fallback');
$empty = new WC_Order_Item_Product(4, 7); $empty->meta['_wutm_shipping_schedule_snapshot'] = plan();
check(!(new WUTM_Shipping_Progress())->item_plan($empty)['plan'], 'Cleared live product does not resurrect stale snapshot');
$snapshot_item = new WC_Order_Item_Product(8, 5);
$fresh->snapshot($snapshot_item, '', array(), $order);
check(isset($snapshot_item->meta['_wutm_shipping_schedule_snapshot']), 'Checkout stores deletion-only fallback snapshot');
check(is_wp_error($fresh->sanitize_plan(array('ship_from' => '2026-02-30'))), 'Invalid calendar date rejected');
check(is_wp_error($fresh->sanitize_plan(array('ship_from' => array()))), 'Malformed date rejected without warnings');
check(is_wp_error($fresh->sanitize_plan(array('ship_from' => "2026-11\0-10"))), 'Null bytes rejected before date parsing');
check(is_wp_error($fresh->sanitize_plan(array('ship_from' => '2026-11-15', 'ship_to' => '2026-11-10'))), 'Reversed range rejected');
check(is_wp_error($fresh->sanitize_plan(array('ship_from' => '2026-11-15', 'arrival_to' => '2026-11-10'))), 'Arrival range cannot end before shipping starts');
check($fresh->sanitize_plan(array('status' => 'shipped'))['status'] === 'preparing', 'Global product status never marks every order shipped');
check($fresh->sanitize_plan(array('status' => 'shipped'), true)['status'] === 'shipped', 'Individual order may be marked shipped');
check($fresh->sanitize_plan(array('message' => '<script>alert(1)</script>通知'))['message'] === 'alert(1)通知', 'Public message sanitized as plain text');
$caps = array('edit_post'); $product = new WC_Product(5);
$_POST = array('wutm_sp_product_nonce' => 'wrong', 'wutm_sp_product' => array('enabled' => 1) + plan());
$fresh->save_product($product);
check($product->get_meta('_wutm_shipping_schedule')['ship_from'] === '2026-11-20', 'Product nonce guard');
$_POST['wutm_sp_product_nonce'] = 'valid'; $caps = array(); $fresh->save_product($product);
check($product->get_meta('_wutm_shipping_schedule')['ship_from'] === '2026-11-20', 'Product permission guard');
$caps = array('edit_post'); $_POST['wutm_sp_product']['ship_from'] = '2026-13-01'; $fresh->save_product($product);
check($product->get_meta('_wutm_shipping_schedule')['ship_from'] === '2026-11-20' && $errors, 'Invalid edit preserves prior product data');
$_POST['wutm_sp_product'] = array('enabled' => 1) + plan('2026-12-10', '2026-12-15'); $fresh->save_product($product);
check($product->get_meta('_wutm_shipping_schedule')['ship_from'] === '2026-12-10' && $product->saved === 0, 'Native product handler updates CRUD object without duplicate save');
$variation_product = new WC_Product(6);
$_POST = array('wutm_sp_variation_nonce' => array(6 => 'wrong'), 'wutm_sp_variation' => array(6 => array('enabled' => 1) + plan('2026-12-10', '2026-12-15')));
$fresh->save_variation($variation_product, 0);
check($variation_product->get_meta('_wutm_shipping_schedule')['ship_from'] === '2026-12-01', 'Variation nonce guard');
$_POST['wutm_sp_variation_nonce'][6] = 'valid'; $fresh->save_variation($variation_product, 0);
check($variation_product->get_meta('_wutm_shipping_schedule')['ship_from'] === '2026-12-10' && $variation_product->saved === 0, 'Variation-specific schedule uses native CRUD save cycle');
$_POST['wutm_sp_variation'][6] = array('present' => 1); $fresh->save_variation($variation_product, 0);
check(!$variation_product->get_meta('_wutm_shipping_schedule'), 'Unchecked variation clears its override to inherit parent');
$_POST = array('wutm_sp_product_nonce' => 'valid', 'wutm_sp_product' => array('present' => 1));
$fresh->save_product($product);
check(!$product->get_meta('_wutm_shipping_schedule'), 'Unchecked product schedule clears metadata');
$caps = array('edit_shop_orders');
$_POST = array('wutm_sp_order_nonce' => 'wrong', 'wutm_sp_items' => array(1 => array('mode' => 'custom') + plan()));
$fresh->save_order(42);
check(!$item->get_meta('_wutm_shipping_schedule_override') && $item->saved === 0, 'Order nonce guard preserves existing item data');
$_POST = array('wutm_sp_order_nonce' => 'valid', 'wutm_sp_items' => array(1 => array('mode' => 'custom', 'status' => 'shipped') + plan(), 9999 => array('mode' => 'custom') + plan()));
$fresh->save_order(42);
check($item->get_meta('_wutm_shipping_schedule_override')['status'] === 'shipped' && $item->saved === 1, 'Native/HPOS order uses item CRUD, ignoring foreign item IDs');
$_POST['wutm_sp_items'] = array(1 => array('mode' => 'custom') + plan('2026-12-10', '2026-12-15'), 2 => array('mode' => 'custom', 'ship_from' => 'invalid'));
$fresh->save_order(42);
check($item->get_meta('_wutm_shipping_schedule_override')['ship_from'] === '2026-11-10', 'Whole order edits validated before any item writes');
$_POST['wutm_sp_items'] = array(1 => array('mode' => 'inherit'));
$fresh->save_order(42);
check(!$item->get_meta('_wutm_shipping_schedule_override'), 'Restore product inheritance removes only order override');
$caps = array(); $_POST = array(); $_GET = array();
$order_reads = 0;
check(is_wp_error($fresh->lookup_order('42', 'customer@example.test', 'wrong')) && $order_reads === 0, 'Lookup nonce fails before order query');
check(is_wp_error($fresh->lookup_order('42', 'wrong@example.test', 'valid')), 'Wrong email cannot access order');
check($fresh->lookup_order('#WU-42', 'CUSTOMER@EXAMPLE.TEST', 'valid') === $order, 'Custom order-number filter and case-insensitive billing email supported');
WC_Rate_Limiter::$blocked = true; $order_reads = 0;
check($fresh->lookup_order('42', 'customer@example.test', 'valid')->get_error_code() === 'rate_limit' && $order_reads === 0, 'Throttled request performs no order query');
WC_Rate_Limiter::$blocked = false;
$guest = new WUTM_Shipping_Progress();
ob_start(); $guest->order_details($order); $output = ob_get_clean();
check($output === '', 'Guest cannot bypass query authorization via order details hook');
$user_id = 72;
ob_start(); $guest->order_details($order); $output = ob_get_clean();
check($output === '', 'Different logged-in customer cannot view order');
$user_id = 71;
ob_start(); $guest->order_details($order); $output = ob_get_clean();
check(strpos($output, '2026/11/20') !== false && strpos($output, '2026/12/01') !== false, 'Owner sees latest parent and variation estimates');
check(strpos($output, 'PRIVATE_ADDRESS') === false && strpos($output, 'customer@example.test') === false && strpos($output, 'secret_order_key') === false, 'Progress exposes no address, email or order key');
$order->status = 'cancelled';
ob_start(); $guest->order_details($order); $output = ob_get_clean();
check(strpos($output, '歷史參考') !== false && strpos($output, 'wutm-sp-steps') === false, 'Closed order shows historical estimates without active progress steps');
$order->status = 'processing';
$user_id = 0; $native = new WUTM_Shipping_Progress(); $native->native_tracking_verified(42);
ob_start(); $native->order_details($order); $output = ob_get_clean();
check(strpos($output, '商品出貨進度') !== false, 'Woo verified native tracking exposes progress after successful authorization');
$user_id = 0; $received = true; $_GET['key'] = 'wrong';
ob_start(); $guest->order_details($order); $output = ob_get_clean(); check($output === '', 'Thank-you page requires correct order key');
$_GET['key'] = 'secret_order_key';
ob_start(); $guest->order_details($order); $output = ob_get_clean(); check(strpos($output, '商品出貨進度') !== false, 'Verified guest thank-you page supported');
$received = false; $_GET = array();
$_POST = array('wutm_sp_lookup' => 1, 'wutm_sp_order' => '42', 'wutm_sp_email' => 'wrong@example.test', 'wutm_sp_nonce' => 'valid');
$output = (new WUTM_Shipping_Progress())->shortcode();
check(strpos($output, 'wutm-sp-card') === false && strpos($output, '找不到符合的訂單') !== false, 'Failed query displays generic error without product data');
$_POST['wutm_sp_email'] = 'customer@example.test';
$output = (new WUTM_Shipping_Progress())->shortcode();
check(strpos($output, '2026/11/20') !== false && strpos($output, 'PRIVATE_ADDRESS') === false, 'Successful query renders only authorized progress');
$_POST = array();
check((new WUTM_Shipping_Progress())->replace_tracking(false, 'woocommerce_order_tracking') === false, 'Woo tracking unchanged by default');
$options['wutm_shipping_progress_options'] = array('replace_tracking' => 1, 'page_id' => 100);
$replace = new WUTM_Shipping_Progress();
check(strpos($replace->replace_tracking(false, 'woocommerce_order_tracking'), '出貨進度查詢') !== false, 'Opt-in replacement of Woo tracking shortcode');
check($replace->replace_tracking('other handler', 'woocommerce_order_tracking') === 'other handler', 'Existing shortcode override respected');
$options['wutm_shipping_notification_email_options'] = array('body' => '<p>自訂舊範本</p>', 'subject' => '出貨通知');
$email = new WUTM_Shipping_Notification_Email();
$built = (new ReflectionMethod($email, 'build_email'))->invoke($email, $order);
check(strpos($built['body'], '自訂舊範本') !== false && strpos($built['body'], '2026/11/20') !== false && strpos($built['body'], '查看最新出貨進度') !== false, 'Legacy email template preserved and current schedule appended');
check(strpos($built['body'], 'secret_order_key') === false, 'Email query link contains no bearer credential');
$screen = array('id' => 'dashboard'); $styles = array(); $scripts = array(); $replace->admin_assets(); $replace->frontend_assets();
check(!$styles && !$scripts, 'No new assets on unrelated admin or storefront pages');
$screen = array('id' => 'woocommerce_page_wc-orders'); $replace->admin_assets();
check(in_array('wutm-shipping-progress-admin', $scripts, true), 'HPOS admin assets loaded only on target screen');
$caps = array('manage_woocommerce');
ob_start(); $replace->admin_panel(); $admin_html = ob_get_clean();
check($catalog_query['posts_per_page'] === 12 && $catalog_query['update_post_meta_cache'], 'Catalog UI is paginated, not an unbounded scan');
check(strpos($admin_html, 'wutm_sp_create_page') !== false && strpos($admin_html, 'wutm_sp_save_product') !== false, 'Central product editing and explicit page-creation actions visible');
$options['wutm_shipping_progress_options'] = array(); $creator = new WUTM_Shipping_Progress(); $created_pages = array();
try { $creator->create_page(); } catch (Test_Response $error) {}
check(!$created_pages, 'Page creation requires publish_pages capability');
$caps = array('manage_woocommerce', 'publish_pages'); $valid_admin_nonce = false;
try { $creator->create_page(); } catch (Test_Response $error) {}
check(!$created_pages, 'Page creation requires valid nonce');
$valid_admin_nonce = true;
try { $creator->create_page(); } catch (Test_Response $error) {}
check(count($created_pages) === 1 && $created_pages[0]['post_content'] === '[wutm_shipping_progress]' && $options['wutm_shipping_progress_options']['page_id'] === 100, 'Explicit creation publishes query page and stores page ID');
try { $creator->create_page(); } catch (Test_Response $error) {}
check(count($created_pages) === 1, 'Repeated page creation does not duplicate configured page');
if (empty($wutm_sp_preview)) echo "Shipping progress checks passed.\n";
