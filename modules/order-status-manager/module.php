<?php
/**
 * WU 訂單狀態管理：集中修改 WooCommerce 既有訂單狀態的顯示名稱。
 */
defined('ABSPATH') || exit;

function wutm_order_status_labels(): array {
    $labels = get_option('wutm_order_status_labels', array());
    return is_array($labels) ? array_map('sanitize_text_field', $labels) : array();
}

function wutm_order_status_label(string $status, string $fallback): string {
    $labels = wutm_order_status_labels();
    return isset($labels[$status]) && $labels[$status] !== '' ? $labels[$status] : $fallback;
}

add_filter('wc_order_statuses', static function (array $statuses): array {
    foreach ($statuses as $status => $label) $statuses[$status] = wutm_order_status_label($status, $label);
    return $statuses;
});
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

add_action('admin_menu', static function (): void {
    add_submenu_page('wu-toolbox-modular', '訂單狀態管理', '訂單狀態管理', 'manage_woocommerce', 'wu-order-status-manager', 'wutm_order_status_manager_page');
});

function wutm_order_status_manager_page(): void {
    if (!current_user_can('manage_woocommerce')) wp_die('您沒有管理訂單狀態的權限。');
    $statuses = function_exists('wc_get_order_statuses') ? wc_get_order_statuses() : array();
    if (isset($_POST['wutm_order_status_save'])) {
        check_admin_referer('wutm_order_status_save');
        $saved = array();
        $posted = isset($_POST['wutm_status_label']) && is_array($_POST['wutm_status_label']) ? $_POST['wutm_status_label'] : array();
        foreach ($statuses as $status => $default) {
            $value = isset($posted[$status]) ? sanitize_text_field(wp_unslash($posted[$status])) : '';
            if ($value !== '' && $value !== $default) $saved[$status] = $value;
        }
        update_option('wutm_order_status_labels', $saved, false);
        echo '<div class="notice notice-success is-dismissible"><p>訂單狀態名稱已儲存。</p></div>';
    }
    $labels = wutm_order_status_labels();
    echo '<div class="wrap"><h1>訂單狀態管理</h1><p>檢視 WooCommerce 目前可用的訂單狀態，並設定顯示名稱。名稱會同步顯示在後台訂單、我的帳號訂單列表，以及對應的顧客訂單通知標題。</p>';
    if (!$statuses) { echo '<div class="notice notice-warning"><p>需要啟用 WooCommerce 後才能管理訂單狀態。</p></div></div>'; return; }
    echo '<form method="post"><table class="widefat striped"><thead><tr><th>狀態代碼</th><th>目前名稱</th><th>顯示名稱</th></tr></thead><tbody>';
    foreach ($statuses as $status => $default) {
        $value = $labels[$status] ?? $default;
        echo '<tr><td><code>' . esc_html($status) . '</code></td><td>' . esc_html($default) . '</td><td><input class="regular-text" type="text" name="wutm_status_label[' . esc_attr($status) . ']" value="' . esc_attr($value) . '"></td></tr>';
    }
    echo '</tbody></table>';
    wp_nonce_field('wutm_order_status_save');
    submit_button('儲存訂單狀態名稱', 'primary', 'wutm_order_status_save');
    echo '</form></div>';
}
