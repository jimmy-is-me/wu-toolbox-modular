<?php
/** One rewrite lifecycle for the combined module, including unloaded AJAX toggles. */
defined('ABSPATH') || exit;
function wutm_member_routes_pending(string $key, bool $enabled): void {
    if ($key === 'member-loyalty') update_option('wutm_member_routes_pending', 1, false);
}
add_action('wutm_module_toggled', 'wutm_member_routes_pending', 10, 2);
add_action('wp_loaded', function (): void {
    $enabled = wutm_is_enabled('member-loyalty') && class_exists('WooCommerce');
    $signature = '1:' . (int) $enabled;
    if (!get_option('wutm_member_routes_pending') && get_option('wutm_member_routes_state') === $signature) return;
    global $wp_rewrite;
    // A disable request may still have the module's callbacks loaded in memory.
    $wp_rewrite->endpoints = array_values(array_filter((array)$wp_rewrite->endpoints, static function($endpoint) {
        return !in_array($endpoint[1] ?? '', ['my-points', 'vip-tier'], true);
    }));
    if ($enabled) {
        add_rewrite_endpoint('my-points', EP_ROOT | EP_PAGES);
        add_rewrite_endpoint('vip-tier', EP_ROOT | EP_PAGES);
    }
    try {
        flush_rewrite_rules(false);
        $rules = get_option('rewrite_rules');
        if (!is_array($rules)) throw new RuntimeException('Rewrite rules were not persisted.');
        update_option('wutm_member_routes_state', $signature, false);
        delete_option('wutm_member_routes_pending');
        delete_option('wutm_member_routes_error');
    } catch (Throwable $error) {
        update_option('wutm_member_routes_error', ['time'=>time(), 'message'=>'會員路由刷新未完成，請檢查主機與資料庫狀態。'], false);
        update_option('wutm_member_routes_pending', 1, false);
    }
}, 100);
