<?php
/** Disposable real WordPress/MySQL/cron integration with a minimal WC CRUD adapter. */
if (!defined('WP_CLI') || !WP_CLI || getenv('WUTM_MEDIA_INTEGRATION') !== '1') exit;
register_post_type('product', ['public' => true, 'supports' => ['title']]);
// This CI installation intentionally has no live WooCommerce/store. Persist the
// CRUD fixture through real wp_update_post, including native save/status hooks.
class WUTM_Unpublish_WP_Product {
    private $id; private $status;
    function __construct($id) { $this->id = $id; $this->status = get_post_status($id); }
    function get_id() { return $this->id; }
    function get_parent_id() { return 0; }
    function get_status() { return $this->status; }
    function set_status($status) { $this->status = $status; }
    function save() { return wp_update_post(['ID' => $this->id, 'post_status' => $this->status]); }
}
function wc_get_product($id) { return get_post($id) ? new WUTM_Unpublish_WP_Product($id) : false; }
function wc_delete_product_transients($id) {}
require dirname(__DIR__) . '/modules/product-scheduled-unpublish/module.php';
function unpublish_assert($value, $message) { if (!$value) throw new RuntimeException($message); }
$oldZone = get_option('timezone_string'); $ids = [];
try {
    update_option('timezone_string', 'Asia/Taipei');
    foreach (['Due A','Due B','Future','Untouched'] as $title) $ids[] = wp_insert_post(['post_type' => 'product', 'post_status' => 'publish', 'post_title' => $title]);
    $past = wp_date('Y-m-d\TH:i', time() - 120); $future = wp_date('Y-m-d\TH:i', time() + 3600);
    update_post_meta($ids[0], '_expiration_datetime', $past);
    update_post_meta($ids[1], '_expiration_datetime', $past);
    update_post_meta($ids[2], '_expiration_datetime', $future);
    wutm_product_reconcile_unpublish($ids[2]);
    $alarm = wp_next_scheduled(WUTM_UNPUBLISH_HOOK, [$ids[2]]);
    unpublish_assert($alarm === wutm_product_unpublish_timestamp($future), 'Real cron timestamp/timezone mismatch');
    wp_clear_scheduled_hook(WUTM_UNPUBLISH_HOOK, [$ids[2]]);
    $_POST = ['wu_unpublish_nonce' => wp_create_nonce('wu_unpublish_nonce_action'), '_expiration_datetime' => $future];
    wutm_product_save_and_schedule_unpublish_from_box($ids[2], get_post($ids[2]), true);
    unpublish_assert(wp_next_scheduled(WUTM_UNPUBLISH_HOOK, [$ids[2]]) === $alarm, 'Real missing alarm repair failed');
    do_action('wutm_product_unpublish_recovery');
    unpublish_assert(get_post_status($ids[0]) === 'publish' && get_post_status($ids[1]) === 'publish' && get_post_meta($ids[0], '_wutm_sale_closed_at', true), 'Real SQL due-deadline query or CRUD draft transition failed');
    unpublish_assert(get_post_status($ids[2]) === 'publish' && get_post_status($ids[3]) === 'publish', 'Future/unscheduled products were changed');
    unpublish_assert(get_post_meta($ids[0], '_wutm_unpublish_result', true)['status'] === 'success', 'Result not persisted');
    unpublish_assert(get_post_meta($ids[0], '_expiration_datetime', true) === $past, 'Published product deadline must remain for purchase blocking');
    // Stale callback must observe a new future value instead of drafting early.
    do_action(WUTM_UNPUBLISH_HOOK, $ids[2]);
    unpublish_assert(get_post_status($ids[2]) === 'publish', 'Stale event unpublished a future product');
    echo "Real WordPress/MySQL unpublish integration passed: timezone, native cron repair, bounded SQL recovery, published status, result persistence and untouched future products.\n";
} finally {
    $_POST = [];
    foreach ($ids as $id) { wp_clear_scheduled_hook(WUTM_UNPUBLISH_HOOK, [$id]); wp_delete_post($id, true); }
    update_option('timezone_string', $oldZone);
    wp_clear_scheduled_hook('wutm_product_unpublish_recovery');
}
