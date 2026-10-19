<?php
/** Site-timezone deadlines, repairable alarms and bounded missed-event recovery. */
defined('ABSPATH') || exit;
const WUTM_UNPUBLISH_HOOK = 'wutm_product_execute_single_product_unpublish';

function wutm_product_unpublish_timestamp($value): int {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/D', $value)) return 0;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, wp_timezone());
    $errors = DateTimeImmutable::getLastErrors();
    return $date && (!$errors || (!$errors['warning_count'] && !$errors['error_count'])) && $date->format('Y-m-d\TH:i') === $value ? $date->getTimestamp() : 0;
}
function wutm_product_unpublish_result(int $id, string $status, string $message): void {
    update_post_meta($id, '_wutm_unpublish_result', ['time' => time(), 'status' => $status, 'message' => $message]);
    wutm_product_log_unpublish_result($id, $message);
}
function wutm_product_unpublish_error(string $message): void {
    if (class_exists('WC_Admin_Meta_Boxes')) WC_Admin_Meta_Boxes::add_error($message);
}
function wutm_product_reconcile_unpublish(int $id): bool {
    $timestamp = wutm_product_unpublish_timestamp(get_post_meta($id, '_expiration_datetime', true));
    if (!$timestamp || get_post_status($id) !== 'publish') return false;
    // Past deadlines must also have an alarm; do not silently leave them published.
    $target = max(time() + 1, $timestamp);
    $next = wp_next_scheduled(WUTM_UNPUBLISH_HOOK, [$id]);
    if (!$next || ($timestamp > time() && (int) $next !== $timestamp) || ($timestamp <= time() && $next > time() + 60)) {
        wp_clear_scheduled_hook(WUTM_UNPUBLISH_HOOK, [$id]);
        $scheduled = wp_schedule_single_event($target, WUTM_UNPUBLISH_HOOK, [$id], true);
        if (is_wp_error($scheduled) || !$scheduled) {
            wutm_product_unpublish_result($id, 'error', '排程未成功建立，請重新儲存並檢查 WP-Cron。');
            return false;
        }
    }
    if ($timestamp <= time()) {
        // Run after WooCommerce finishes its product-save hooks, not recursively
        // inside the publish/meta box save where a later save can republish it.
        static $queued = [];
        if (empty($queued[$id])) {
            $queued[$id] = true;
            add_action('shutdown', static function () use ($id): void { wutm_product_do_unpublish_single_product($id); });
        }
    }
    return true;
}
add_action('save_post_product', 'wutm_product_save_and_schedule_unpublish_from_box', 20, 3);
function wutm_product_save_and_schedule_unpublish_from_box($post_id, $post, $update): void {
    if (!empty($GLOBALS['wutm_unpublish_running'][$post_id]) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)
        || !isset($_POST['wu_unpublish_nonce'], $_POST['_expiration_datetime'])
        || !is_string($_POST['wu_unpublish_nonce']) || !is_string($_POST['_expiration_datetime'])
        || !wp_verify_nonce(wp_unslash($_POST['wu_unpublish_nonce']), 'wu_unpublish_nonce_action')
        || !current_user_can('edit_post', $post_id) || $post->post_type !== 'product') return;
    $value = sanitize_text_field(wp_unslash($_POST['_expiration_datetime']));
    // Validate before touching the existing deadline/alarm. Invalid input must
    // never cancel a previously valid schedule.
    if ($value !== '' && !wutm_product_unpublish_timestamp($value)) {
        wutm_product_unpublish_error('排程下架時間無效；原有排程已保留。請依網站時區輸入有效日期與時間。');
        return;
    }
    if ($value === '') {
        delete_post_meta($post_id, '_expiration_datetime');
        wp_clear_scheduled_hook(WUTM_UNPUBLISH_HOOK, [(int) $post_id]);
        return;
    }
    if (get_post_meta($post_id, '_expiration_datetime', true) !== $value) update_post_meta($post_id, '_expiration_datetime', $value);
    // Deliberately repair an unchanged deadline when its alarm has disappeared.
    if (get_post_status($post_id) === 'publish' && !wutm_product_reconcile_unpublish((int) $post_id)) {
        wutm_product_unpublish_error('下架時間已儲存，但排程未建立；請檢查 WP-Cron。');
    }
}
add_action(WUTM_UNPUBLISH_HOOK, 'wutm_product_do_unpublish_single_product');
function wutm_product_do_unpublish_single_product($post_id): void {
    $id = (int) $post_id;
    if (!$id || !empty($GLOBALS['wutm_unpublish_running'][$id]) || get_post_type($id) !== 'product') return;
    // Re-read the current deadline: cancelled, rescheduled and stale callbacks
    // must not unpublish a product early or after its schedule was cleared.
    $timestamp = wutm_product_unpublish_timestamp(get_post_meta($id, '_expiration_datetime', true));
    if (!$timestamp || get_post_status($id) !== 'publish') return;
    if ($timestamp > time()) { wutm_product_reconcile_unpublish($id); return; }
    $GLOBALS['wutm_unpublish_running'][$id] = true;
    try {
        $product = wc_get_product($id);
        if (!$product || $product->get_status() !== 'publish') return;
        $product->set_status('draft');
        if (!$product->save() || get_post_status($id) !== 'draft') throw new RuntimeException('Product status was not saved');
        delete_post_meta($id, '_expiration_datetime');
        wp_clear_scheduled_hook(WUTM_UNPUBLISH_HOOK, [$id]);
        wutm_product_unpublish_result($id, 'success', '已依排程轉為草稿');
        wc_delete_product_transients($id);
        clean_post_cache($id);
        // WooCommerce/core hooks handle the normal cache lifecycle. Notify the
        // common page-cache integrations too; external CDN caches need their own purge.
        foreach (['rocket_clean_post', 'w3tc_flush_post', 'wp_cache_post_change'] as $purge) if (function_exists($purge)) $purge($id);
        do_action('litespeed_purge_post', $id);
        do_action('wutm_product_unpublished', $id);
    } catch (Throwable $error) {
        if (get_post_status($id) === 'draft') wutm_product_unpublish_result($id, 'warning', '商品已轉為草稿；快取清除未完成，請檢查快取／CDN。');
        else wutm_product_unpublish_result($id, 'error', '下架未完成；保留時間，等待補執行或重新儲存。');
    } finally {
        unset($GLOBALS['wutm_unpublish_running'][$id]);
    }
}
// Catch products republished through native/REST/import flows with an existing deadline.
add_action('transition_post_status', static function ($new, $old, $post): void {
    if ($new === 'publish' && $old !== 'publish' && $post->post_type === 'product' && empty($GLOBALS['wutm_unpublish_running'][$post->ID])) wutm_product_reconcile_unpublish((int) $post->ID);
}, 20, 3);

add_filter('cron_schedules', static function (array $schedules): array {
    $schedules['wutm_unpublish_five_minutes'] = ['interval' => 300, 'display' => 'WU 商品下架補檢（5 分鐘）'];
    return $schedules;
});
add_action('admin_init', 'wutm_product_ensure_unpublish_recovery');
function wutm_product_ensure_unpublish_recovery(): void {
    if (!wp_next_scheduled('wutm_product_unpublish_recovery')) wp_schedule_event(time() + 60, 'wutm_unpublish_five_minutes', 'wutm_product_unpublish_recovery');
}
add_action('wutm_module_toggled', static function ($key, $enabled): void {
    if ($key === 'product-scheduled-unpublish' && !$enabled) wp_clear_scheduled_hook('wutm_product_unpublish_recovery');
}, 10, 2);
add_action('wutm_product_unpublish_recovery', static function (): void {
    // A bounded background query only; never scan the catalogue on frontend requests.
    $ids = get_posts(['post_type' => 'product', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 20,
        'no_found_rows' => true, 'orderby' => 'ID', 'order' => 'ASC',
        'meta_query' => [
            ['key' => '_expiration_datetime', 'value' => '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$', 'compare' => 'REGEXP'],
            ['key' => '_expiration_datetime', 'value' => wp_date('Y-m-d\TH:i'), 'compare' => '<='],
        ]]);
    $started = microtime(true);
    foreach ($ids as $id) {
        wutm_product_do_unpublish_single_product((int) $id);
        if (microtime(true) - $started > 5) break;
    }
});
// Gate purchases even if WP-Cron is delayed. This reads only the current product's
// cached metadata, supports variations, and does not change stock or prices.
function wutm_product_deadline_purchasable($purchasable, $product): bool {
    if (!$purchasable) return false;
    $id = $product->get_parent_id() ?: $product->get_id();
    $timestamp = wutm_product_unpublish_timestamp(get_post_meta($id, '_expiration_datetime', true));
    return !$timestamp || $timestamp > time();
}
add_filter('woocommerce_is_purchasable', 'wutm_product_deadline_purchasable', 20, 2);
add_filter('woocommerce_variation_is_purchasable', 'wutm_product_deadline_purchasable', 20, 2);
add_action('template_redirect', static function (): void {
    if (!is_singular('product') || is_preview()) return;
    $id = (int) get_queried_object_id();
    $timestamp = wutm_product_unpublish_timestamp(get_post_meta($id, '_expiration_datetime', true));
    if (!$timestamp || $timestamp > time()) return;
    wutm_product_do_unpublish_single_product($id);
    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();
}, 1);
