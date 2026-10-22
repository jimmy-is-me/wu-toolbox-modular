<?php
/** Adapter only: preserve source settings IDs, API payloads and order metadata. */
defined('ABSPATH') || exit;

/** Only wrap the source's manual AJAX operations, never global WP handlers. */
function wutm_invoice_ajax_callback(callable $callback): callable {
    return static function (...$args) use ($callback): void {
        try { call_user_func_array($callback, $args); }
        catch (Throwable $error) {
            // No messages, arguments, keys, payloads or customer data in logs.
            if (function_exists('error_log')) error_log('[WU invoice operation] ' . json_encode(['type'=>get_class($error),'file'=>basename($error->getFile()),'line'=>$error->getLine()]));
            wp_send_json_error(['message'=>'發票操作未能確認完成。請先至服務商查詢是否已開立／作廢，確認後再操作，避免重複送出。'], 502);
        }
    };
}

function wutm_invoice_runtime_problem(string $provider): string {
    if (PHP_VERSION_ID < 80000) return '此發票模組需要 PHP 8.0 以上。';
    if (!class_exists('WooCommerce')) return '請先啟用 WooCommerce。';
    $other = $provider === 'ezpay' ? 'ecpay' : 'ezpay';
    if (wutm_is_enabled($other . '-invoice')) return '請只啟用一種電子發票模組，先關閉另一張發票卡片，避免重複開票。';
    if (defined('WOOMP_PLUGIN_DIR') || defined(strtoupper($other) . 'INVOICE_PLUGIN_DIR')) return '偵測到其他發票外掛，請先停用重複的發票服務。';
    if (defined('WOOECPAY_VERSION') && get_option('wooecpay_enabled_invoice', 'yes') === 'yes') return '現有綠界金流／物流工具的電子發票仍啟用，請先將該工具的電子發票功能關閉；金流與物流可以繼續使用。';
    return '';
}
function wutm_invoice_settings_page(string $provider) {
    static $pages = [];
    if (!class_exists('WC_Settings_Page') && defined('WC_ABSPATH')) require_once WC_ABSPATH . 'includes/admin/settings/class-wc-settings-page.php';
    if (!isset($pages[$provider])) {
        $class = $provider === 'ezpay' ? 'WuStandaloneEzpay\\Settings' : 'WuStandaloneEcpay\\Settings';
        $pages[$provider] = class_exists($class) ? new $class() : require WUTM_PATH . 'modules/' . $provider . '-invoice/settings.php';
    }
    return $pages[$provider];
}
function wutm_invoice_register(string $provider): void {
    $label = $provider === 'ezpay' ? 'EZPAY 電子發票' : '綠界電子發票';
    $page_hook = '';
    add_action('admin_menu', static function () use ($provider, $label, &$page_hook): void {
        $page_hook = add_submenu_page('wu-toolbox-modular', $label, $label, 'manage_woocommerce', 'wu-' . $provider . '-invoice', static function () use ($provider, $label): void {
            if (!current_user_can('manage_woocommerce')) return;
            wutm_admin_page_open($label, '保留原發票設定與訂單操作；上線前請先以商家測試帳號驗證。');
            $problem = wutm_invoice_runtime_problem($provider);
            if ($problem) echo '<div class="notice notice-warning inline"><p>' . esc_html($problem) . '</p></div>';
            if (!class_exists('WC_Admin_Settings') && defined('WC_ABSPATH')) require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
            if (PHP_VERSION_ID >= 80000 && class_exists('WC_Admin_Settings')) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wutm_save_' . esc_attr($provider) . '_invoice">';
                wp_nonce_field('wutm_save_' . $provider . '_invoice');
                WC_Admin_Settings::output_fields(wutm_invoice_settings_page($provider)->get_settings());
                submit_button('儲存設定');
                echo '</form>';
            }
            wutm_admin_page_close();
        });
    });
    add_action('admin_enqueue_scripts', static function ($hook) use (&$page_hook): void {
        if (!$page_hook || $hook !== $page_hook || !current_user_can('manage_woocommerce')) return;
        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_script('wc-enhanced-select');
    }, 20);
    add_action('admin_post_wutm_save_' . $provider . '_invoice', static function () use ($provider): void {
        if (!current_user_can('manage_woocommerce')) wp_die('沒有管理商店權限。', '', ['response'=>403]);
        check_admin_referer('wutm_save_' . $provider . '_invoice');
        if (!class_exists('WC_Admin_Settings') && defined('WC_ABSPATH')) require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
        if (PHP_VERSION_ID < 80000 || !class_exists('WC_Admin_Settings')) wp_die('目前環境無法儲存發票設定。');
        WC_Admin_Settings::save_fields(wutm_invoice_settings_page($provider)->get_settings());
        wp_safe_redirect(admin_url('admin.php?page=wu-' . $provider . '-invoice&saved=1'));
        exit;
    });
    // Resolve other plugins' priority-20 bootstraps first; never include after a
    // plugins_loaded callback has already missed the only loading opportunity.
    add_action('plugins_loaded', static function () use ($provider): void {
        $problem = wutm_invoice_runtime_problem($provider);
        if ($problem || defined(strtoupper($provider) . 'INVOICE_PLUGIN_DIR')) {
            add_action('admin_notices', static function () use ($problem): void {
                echo '<div class="notice notice-warning"><p>' . esc_html($problem ?: '同名獨立發票外掛已載入，Toolbox 不會重複載入。') . '</p></div>';
            });
            return;
        }
        require_once WUTM_PATH . 'modules/' . $provider . '-invoice/wu-' . $provider . '-invoice.php';
        $suffix = $provider === 'ezpay' ? '_ezpay' : '';
        foreach (['gen_invoice', 'update_invoice', 'invalid_invoice'] as $action) {
            add_action('wp_ajax_' . $action . $suffix, static function (): void {
                check_ajax_referer('invoice_handler', 'nonce');
                $id = absint($_POST['orderId'] ?? 0);
                if (!current_user_can('manage_woocommerce') || !current_user_can('edit_shop_orders') || !$id || !wc_get_order($id)) wp_send_json_error(['message'=>'無法操作此訂單發票。'],403);
            }, 1);
        }
    }, 30);
}
