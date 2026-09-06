<?php
/**
 * WU 訂單狀態管理：調整既有狀態名稱及其可用性。
 */
defined('ABSPATH') || exit;

function wutm_order_status_labels(): array {
    $labels = get_option('wutm_order_status_labels', array());
    return is_array($labels) ? array_map('sanitize_text_field', $labels) : array();
}

function wutm_disabled_order_statuses(): array {
    $statuses = get_option('wutm_disabled_order_statuses', array());
    return is_array($statuses) ? array_values(array_filter(array_map('sanitize_key', $statuses))) : array();
}

function wutm_order_status_label(string $status, string $fallback): string {
    $labels = wutm_order_status_labels();
    return isset($labels[$status]) && $labels[$status] !== '' ? $labels[$status] : $fallback;
}

/**
 * Read registered WooCommerce post statuses directly so settings can still list
 * statuses which the merchant has previously disabled.
 */
function wutm_all_order_statuses(): array {
    $statuses = array();
    foreach (get_post_stati(array(), 'objects') as $status => $object) {
        if (strpos($status, 'wc-') !== 0) continue;
        $statuses[$status] = isset($object->label) ? (string) $object->label : $status;
    }
    foreach (function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array() as $status => $label) {
        if (!isset($statuses[$status])) $statuses[$status] = $label;
    }
    return $statuses;
}

add_filter('wc_order_statuses', static function (array $statuses): array {
    $disabled = wutm_disabled_order_statuses();
    foreach ($statuses as $status => $label) {
        if (in_array($status, $disabled, true)) {
            unset($statuses[$status]);
            continue;
        }
        $statuses[$status] = wutm_order_status_label($status, $label);
    }
    return $statuses;
}, 99);

// Keep names visible on existing orders even after a status has been disabled.
add_filter('woocommerce_order_status_name', static function ($label, $status): string {
    return wutm_order_status_label((string) $status, (string) $label);
}, 10, 2);

// Native customer emails use dedicated headings. Keep those headings aligned with
// the renamed order status without overwriting merchant-written custom headings.
add_filter('woocommerce_email_heading_customer_processing_order', static function ($heading): string { return wutm_order_status_label('wc-processing', $heading); });
add_filter('woocommerce_email_heading_customer_completed_order', static function ($heading): string { return wutm_order_status_label('wc-completed', $heading); });
add_filter('woocommerce_email_heading_customer_on_hold_order', static function ($heading): string { return wutm_order_status_label('wc-on-hold', $heading); });
add_filter('woocommerce_email_heading_customer_refunded_order', static function ($heading): string { return wutm_order_status_label('wc-refunded', $heading); });
add_filter('woocommerce_email_heading_customer_cancelled_order', static function ($heading): string { return wutm_order_status_label('wc-cancelled', $heading); });
add_filter('woocommerce_email_heading_failed_order', static function ($heading): string { return wutm_order_status_label('wc-failed', $heading); });

/**
 * Remove disabled statuses from both legacy and HPOS order bulk-action menus.
 */
function wutm_filter_order_bulk_actions(array $actions): array {
    foreach (wutm_disabled_order_statuses() as $status) {
        $slug = substr($status, 3);
        foreach (array('mark_' . $slug, 'change_status_to_' . $slug, 'wc_mark_' . $slug) as $action) {
            unset($actions[$action]);
        }
    }
    return $actions;
}
add_filter('bulk_actions-edit-shop_order', 'wutm_filter_order_bulk_actions', 99);
add_filter('bulk_actions-woocommerce_page_wc-orders', 'wutm_filter_order_bulk_actions', 99);

add_action('admin_menu', static function (): void {
    add_submenu_page('wu-toolbox-modular', '訂單狀態管理', '訂單狀態管理', 'manage_woocommerce', 'wu-order-status-manager', 'wutm_order_status_manager_page');
});

function wutm_order_status_manager_page(): void {
    if (!current_user_can('manage_woocommerce')) wp_die('您沒有管理訂單狀態的權限。');
    $statuses = wutm_all_order_statuses();

    if (isset($_POST['wutm_order_status_save'])) {
        check_admin_referer('wutm_order_status_save');
        $saved_labels = array();
        $disabled = array();
        $posted_labels = isset($_POST['wutm_status_label']) && is_array($_POST['wutm_status_label']) ? $_POST['wutm_status_label'] : array();
        $enabled = isset($_POST['wutm_status_enabled']) && is_array($_POST['wutm_status_enabled']) ? $_POST['wutm_status_enabled'] : array();

        foreach ($statuses as $status => $default) {
            $label = isset($posted_labels[$status]) ? sanitize_text_field(wp_unslash($posted_labels[$status])) : '';
            if ($label !== '' && $label !== $default) $saved_labels[$status] = $label;
            if (!isset($enabled[$status])) $disabled[] = $status;
        }
        update_option('wutm_order_status_labels', $saved_labels, false);
        update_option('wutm_disabled_order_statuses', $disabled, false);
        echo '<div class="notice notice-success is-dismissible"><p>訂單狀態設定已儲存。</p></div>';
    }

    $labels = wutm_order_status_labels();
    $disabled = wutm_disabled_order_statuses();
    echo '<div class="wrap"><h1>訂單狀態管理</h1><p>可修改現有訂單狀態的顯示名稱，或關閉不需要的狀態。關閉後將從狀態篩選、新增狀態選單及後台訂單批次操作中移除；既有訂單仍會保留原有狀態與顯示名稱。</p>';
    if (!$statuses) { echo '<div class="notice notice-warning"><p>需要啟用 WooCommerce 後才能管理訂單狀態。</p></div></div>'; return; }
    echo '<form method="post"><table class="widefat striped"><thead><tr><th>狀態代碼</th><th>目前名稱</th><th>顯示名稱</th><th>啟用</th></tr></thead><tbody>';
    foreach ($statuses as $status => $default) {
        $value = $labels[$status] ?? $default;
        $enabled = !in_array($status, $disabled, true);
        echo '<tr><td><code>' . esc_html($status) . '</code></td><td>' . esc_html($default) . '</td><td><input class="regular-text" type="text" name="wutm_status_label[' . esc_attr($status) . ']" value="' . esc_attr($value) . '"></td><td><label><input type="checkbox" name="wutm_status_enabled[' . esc_attr($status) . ']" value="1" ' . checked(true, $enabled, false) . '> 啟用</label></td></tr>';
    }
    echo '</tbody></table>';
    wp_nonce_field('wutm_order_status_save');
    submit_button('儲存訂單狀態設定', 'primary', 'wutm_order_status_save');
    echo '</form></div>';
}
