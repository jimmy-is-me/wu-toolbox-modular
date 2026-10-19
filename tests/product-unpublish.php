<?php
/** Isolated scheduling regression fixtures; never touches a site/database. */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', __DIR__ . '/');
$hooks = []; $meta = []; $statuses = [7 => 'publish']; $events = []; $errors = []; $queries = []; $capable = true; $fail = false; $schedule_fail = false;
function add_action($name, $fn, ...$args) { $GLOBALS['hooks'][$name][] = $fn; }
function add_filter($name, $fn, ...$args) { add_action($name, $fn); }
function do_action($name, ...$args) { foreach ($GLOBALS['hooks'][$name] ?? [] as $fn) $fn(...$args); }
function get_post_meta($id, $key, ...$args) { return $GLOBALS['meta'][$id][$key] ?? ''; }
function update_post_meta($id, $key, $value) { $GLOBALS['meta'][$id][$key] = $value; }
function delete_post_meta($id, $key) { unset($GLOBALS['meta'][$id][$key]); }
function get_post_status($id) { return $GLOBALS['statuses'][$id] ?? false; }
function get_post_type($id) { return isset($GLOBALS['statuses'][$id]) ? 'product' : false; }
function wp_timezone() { return new DateTimeZone('Asia/Taipei'); }
function wp_timezone_string() { return 'Asia/Taipei'; }
function wp_date($format, $time = null) { return (new DateTimeImmutable('@' . ($time ?? time())))->setTimezone(wp_timezone())->format($format); }
function wp_is_post_revision($id) { return false; }
function wp_is_post_autosave($id) { return false; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid'; }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim(strip_tags($value)); }
function current_user_can(...$args) { return $GLOBALS['capable']; }
function wp_next_scheduled($hook, $args = []) { return $GLOBALS['events'][$hook][json_encode($args)] ?? false; }
function wp_clear_scheduled_hook($hook, $args = []) { unset($GLOBALS['events'][$hook][json_encode($args)]); }
function wp_schedule_single_event($time, $hook, $args, ...$rest) { if ($GLOBALS['schedule_fail']) return false; $GLOBALS['events'][$hook][json_encode($args)] = $time; return true; }
function wp_schedule_event($time, $interval, $hook) { return wp_schedule_single_event($time, $hook, []); }
function is_wp_error($value) { return false; }
function wc_delete_product_transients($id) {}
function clean_post_cache($id) {}
function get_posts($args) { $GLOBALS['queries'][] = $args; return array_keys($GLOBALS['statuses']); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES); }
function esc_attr($value) { return esc_html($value); }
function __($value) { return $value; }
function wp_nonce_field($action, $name) { echo '<input type="hidden" name="'.esc_attr($name).'" value="valid">'; }
function is_admin() { return false; }
function is_singular($type) { return !empty($GLOBALS['single_product']); }
function is_preview() { return !empty($GLOBALS['preview']); }
function get_queried_object_id() { return 7; }
function status_header($code) { $GLOBALS['status_code'] = $code; }
function nocache_headers() { $GLOBALS['no_cache'] = true; }
class WC_Admin_Meta_Boxes { static function add_error($value) { $GLOBALS['errors'][] = $value; } }
class WC_Product {
    private $id; private $status; private $parent;
    function __construct($id, $parent = 0) { $this->id = $id; $this->parent = $parent; $this->status = get_post_status($id); }
    function get_id() { return $this->id; }
    function get_parent_id() { return $this->parent; }
    function get_status() { return $this->status; }
    function set_status($status) { $this->status = $status; }
    function save() { if ($GLOBALS['fail']) throw new RuntimeException('Save failure'); $GLOBALS['statuses'][$this->id] = $this->status; wutm_product_save_and_schedule_unpublish_from_box($this->id, (object) ['post_type' => 'product'], true); return $this->id; }
}
function wc_get_product($id) { return isset($GLOBALS['statuses'][$id]) ? new WC_Product($id) : false; }
function WC() { return (object) ['cart' => new class { function get_cart() { return $GLOBALS['cart_items'] ?? []; } }]; }
function wc_has_notice($message, $type) { return in_array($message, $GLOBALS['cart_notices'] ?? [], true); }
function wc_add_notice($message, $type) { $GLOBALS['cart_notices'][] = $message; }
function check($ok, $message) { if (!$ok) throw new RuntimeException($message); }
require dirname(__DIR__) . '/modules/product-scheduled-unpublish/module.php';
check(!$queries && !$events, 'Loading enabled module does not query catalogue or start frontend scanning');
date_default_timezone_set('America/New_York');
check(wutm_product_unpublish_timestamp('2026-10-19T11:02') === gmmktime(3, 2, 0, 10, 19, 2026), 'Deadline uses WordPress timezone, not PHP timezone');
foreach (['2026-02-30T11:00','2026-10-19T25:00','not-date',[], '2026-10-19T11:02junk'] as $bad) check(wutm_product_unpublish_timestamp($bad) === 0, 'Strict dates reject invalid input');
$future = wp_date('Y-m-d\TH:i', time() + 3600); $past = wp_date('Y-m-d\TH:i', time() - 120);
function save_deadline($value, $nonce = 'valid') { $_POST = ['wu_unpublish_nonce' => $nonce, '_expiration_datetime' => $value]; wutm_product_save_and_schedule_unpublish_from_box(7, (object) ['post_type' => 'product'], true); }
save_deadline($future, 'invalid'); check(!get_post_meta(7, '_expiration_datetime'), 'Nonce boundary');
$capable = false; save_deadline($future); check(!get_post_meta(7, '_expiration_datetime'), 'Permission boundary'); $capable = true;
save_deadline($future); $alarm = wp_next_scheduled(WUTM_UNPUBLISH_HOOK, [7]); check($alarm === wutm_product_unpublish_timestamp($future), 'Future alarm registered at real UTC timestamp');
save_deadline('2026-02-30T11:00'); check(get_post_meta(7, '_expiration_datetime') === $future && wp_next_scheduled(WUTM_UNPUBLISH_HOOK, [7]) === $alarm, 'Invalid input preserves deadline and alarm');
$_POST = ['wu_unpublish_nonce' => 'valid']; wutm_product_save_and_schedule_unpublish_from_box(7, (object) ['post_type' => 'product'], true); check(get_post_meta(7, '_expiration_datetime') === $future, 'Absent field does not cancel schedule');
wp_clear_scheduled_hook(WUTM_UNPUBLISH_HOOK, [7]); save_deadline($future); check(wp_next_scheduled(WUTM_UNPUBLISH_HOOK, [7]) === $alarm, 'Unchanged deadline repairs a lost alarm');
wutm_product_do_unpublish_single_product(7); check($statuses[7] === 'publish', 'Early stale callback cannot unpublish a rescheduled product');
save_deadline(''); wutm_product_do_unpublish_single_product(7); check($statuses[7] === 'publish' && !wp_next_scheduled(WUTM_UNPUBLISH_HOOK, [7]), 'Cancelled schedule makes stale callback harmless');
save_deadline($past); check(wp_next_scheduled(WUTM_UNPUBLISH_HOOK, [7]) && $statuses[7] === 'publish', 'Past deadline queued after save, not inside WooCommerce save recursion');
check(!wutm_product_deadline_purchasable(true, new WC_Product(7)) && !wutm_product_deadline_purchasable(true, new WC_Product(8, 7)), 'Expired parent and variation purchases blocked before cron');
check(!wutm_product_deadline_purchasable(false, new WC_Product(99)), 'Preserves original non-purchasable state');
do_action('shutdown'); check($statuses[7] === 'publish' && get_post_meta(7, '_expiration_datetime') === $past && get_post_meta(7, '_wutm_unpublish_result')['status'] === 'success', 'Deadline closes purchases without changing published status or deleting deadline');
check(wutm_product_expired_button_text(new WC_Product(7)) === '此商品已無法購買', 'Default closed button label');
$_POST['_wutm_expired_button_text'] = '<b>預購已結束</b>'; save_deadline($past);
// save_deadline creates a fresh POST; test custom field through the real save callback.
$_POST = ['wu_unpublish_nonce'=>'valid','_expiration_datetime'=>$past,'_wutm_expired_button_text'=>'<b>預購已結束</b>'];
wutm_product_save_and_schedule_unpublish_from_box(7, (object) ['post_type'=>'product'], true);
check(wutm_product_expired_button_text(new WC_Product(8,7)) === '預購已結束', 'Custom sanitized parent/variation button label');
$html = wutm_product_closed_button('<a href="?add-to-cart=7">Buy</a>', new WC_Product(7));
check(strpos($html,'aria-disabled="true"') !== false && strpos($html,'href=') === false && strpos($html,'預購已結束') !== false, 'Loop button becomes non-actionable closed label');
$meta[7]['_expiration_datetime'] = $future; delete_post_meta(7, '_wutm_sale_closed_at');
check(wutm_product_closed_button('original', new WC_Product(7)) === 'original', 'Future button preserved');
$schedule_fail = true; save_deadline($future); check(get_post_meta(7, '_expiration_datetime') === $future, 'Deadline preserved');
$schedule_fail = false; save_deadline($future);
check(wutm_product_deadline_purchasable(true,new WC_Product(7)), 'Future deadline reopens original purchase ability');
$meta[7]['_expiration_datetime'] = $past; do_action('wutm_product_unpublish_recovery');
check($statuses[7] === 'publish' && end($queries)['posts_per_page'] === 20, 'Bounded recovery never drafts');
$cart_items = [['data'=>new WC_Product(7)]];
$checkout_errors = new class { public $messages=[]; function add($code,$message) { $this->messages[$code]=$message; } };
do_action('woocommerce_check_cart_items'); do_action('woocommerce_check_cart_items');
do_action('woocommerce_after_checkout_validation', [], $checkout_errors);
check(count($cart_notices)===1 && isset($checkout_errors->messages['wutm_purchase_deadline']), 'Existing cart and checkout revalidate cutoff, notices deduplicated');
check(!isset($hooks['template_redirect']), 'No expiry 404 handler is installed');
$meta[7]['_expiration_datetime'] = $future;
ob_start(); wutm_product_add_unpublish_field_to_publish_box((object) ['ID'=>7,'post_type'=>'product']); $field_html=ob_get_clean();
check(strpos($field_html,wp_date('M j, Y @ H:i',wutm_product_unpublish_timestamp($future)))!==false && strpos($field_html,'id="wutm-cutoff-editor" hidden')!==false, 'Native publication-date format and collapsed editor');
check(strpos($field_html,'截止後按鈕文字')!==false && strpos($field_html,'轉為草稿')===false && strpos($field_html,'WP-Cron')===false, 'Compact purchase-only explanation and configurable label');
wutm_product_ensure_unpublish_recovery(); check(wp_next_scheduled('wutm_product_unpublish_recovery'), 'Recovery event');
do_action('wutm_module_toggled','product-scheduled-unpublish',false); check(!wp_next_scheduled('wutm_product_unpublish_recovery'), 'Disabled module clears recurring task');
echo "Product deadline passed: timezone, security, repair/cancel/stale alarms, published-status preservation, default/custom non-actionable buttons, variations, no 404 and bounded recovery.\\n";
