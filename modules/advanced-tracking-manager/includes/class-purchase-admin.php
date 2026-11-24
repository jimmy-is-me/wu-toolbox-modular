<?php
defined('ABSPATH') || exit;

final class WUTM_ATM_Purchase_Admin {
    const ACTION = 'wutm_atm_purchase_order';
    const COLUMN = 'wutm_atm_purchase';
    private static $list_rows = null;

    public static function boot(): void {
        add_action('add_meta_boxes', [__CLASS__, 'metabox']);
        add_action('admin_post_' . self::ACTION, [__CLASS__, 'post']);
        if (!WUTM_ATM_Events::settings()['purchase_column']) return;
        foreach (['manage_edit-shop_order_columns','manage_woocommerce_page_wc-orders_columns'] as $hook) {
            add_filter($hook, [__CLASS__, 'column']);
        }
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'legacy_column'], 10, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [__CLASS__, 'render_column'], 10, 2);
        add_filter('woocommerce_shop_order_list_table_prepare_items_query_args', [__CLASS__, 'capture_list_query']);
    }

    public static function metabox(string $screen): void {
        $orders_screen = function_exists('wc_get_page_screen_id') ? wc_get_page_screen_id('shop-order') : 'woocommerce_page_wc-orders';
        if (!WUTM_ATM_Purchase::active() || !in_array($screen, ['shop_order', $orders_screen], true) ||
            !current_user_can('edit_shop_orders')) return;
        add_meta_box('wutm-atm-purchase', 'Meta 購買事件（Purchase）', [__CLASS__, 'render'], $screen, 'side', 'default');
    }

    public static function post(): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') wp_die('請使用訂單頁上的操作。', '', ['response'=>405]);
        $id = is_scalar($_POST['order_id'] ?? null) ? absint($_POST['order_id']) : 0;
        if (!$id || !WUTM_ATM_Purchase::active() || !current_user_can('edit_shop_orders') ||
            !current_user_can('edit_shop_order', $id)) wp_die('權限不足或購買追蹤已停用。', '', ['response'=>403]);
        check_admin_referer(self::ACTION . '_' . $id);
        $order = wc_get_order($id);
        if (!$order instanceof WC_Order || $order->get_type() !== 'shop_order') wp_die('找不到此訂單。', '', ['response'=>404]);
        $action = is_string($_POST['operation'] ?? null) ? sanitize_key($_POST['operation']) : '';
        if (!in_array($action, ['confirm','send','retry','test'], true) ||
            (in_array($action, ['confirm','test'], true) && ($_POST['verified'] ?? '') !== 'yes')) {
            wp_die('請在訂單頁確認操作。', '', ['response'=>400]);
        }
        $result = WUTM_ATM_Purchase::act($id, $action);
        wp_safe_redirect(add_query_arg('wutm_purchase_result', $result, $order->get_edit_order_url()) . '#wutm-atm-purchase');
        exit;
    }

    public static function render($object): void {
        if (!WUTM_ATM_Purchase::active()) return;
        $order = $object instanceof WP_Post ? wc_get_order($object->ID) : $object;
        if (!$order instanceof WC_Order || !$order->get_id() || $order->get_type() !== 'shop_order' ||
            !current_user_can('edit_shop_order', $order->get_id())) return;
        [$row, $tests] = WUTM_ATM_Purchase::channels($order);
        $paid = WUTM_ATM_Purchase::payment($order);
        $consent = WUTM_ATM_Purchase::consent_valid($order);
        $details = WUTM_ATM_Purchase::details($order, $row, $tests, $paid, $consent);
        $messages = [
            'queued'=>'購買事件已建立，等待伺服器發送。','confirmed'=>'已記錄收款確認。請檢查下方的發送狀態。',
            'test'=>'模擬測試已排入佇列。','existing'=>'此訂單已建立此事件，請查看狀態。',
            'consent'=>'此訂單未具備有效的行銷追蹤同意，無法發送 Meta 購買事件。',
            'invalid_total'=>'請確認訂單金額及幣別。','unpaid'=>'訂單目前未符合付款條件或已超過發送期限。',
            'unavailable'=>'設定或訂單狀態已變更，請重新檢查。','busy'=>'暫時無法處理，請重新整理後再試。',
        ];
        $result = is_string($_GET['wutm_purchase_result'] ?? null) ? sanitize_key($_GET['wutm_purchase_result']) : '';
        if (isset($messages[$result])) echo '<p role="status"><strong>' . esc_html($messages[$result]) . '</strong></p>';
        echo '<p><strong>' . esc_html($details['購買事件狀態']) . '</strong></p><dl>';
        foreach (['訂單總額','訂單狀態','付款完成時間','Purchase 付款條件','付款確認來源','交易同意收據','瀏覽器狀態','伺服器狀態','最近發送時間','Meta 接收結果'] as $label) {
            echo '<dt><strong>' . esc_html($label) . '</strong></dt><dd>' . esc_html($details[$label]) . '</dd>';
        }
        echo '</dl><details><summary>事件識別與驗證詳情</summary><dl>';
        foreach (['人工收款紀錄','Purchase event_id','已紀錄的事件金額','原因','測試事件','防重複觀察'] as $label) {
            echo '<dt><strong>' . esc_html($label) . '</strong></dt><dd>' . esc_html($details[$label]) . '</dd>';
        }
        echo '</dl></details>';

        if ($row && $row->status === 'sent' && (int) $row->received > 0) {
            echo '<p>此訂單已成功回傳 Meta 購買事件，無須再次發送。</p>';
        } elseif ($row && in_array($row->status, ['pending','sending','retry'], true)) {
            echo '<p>購買事件已建立，等待伺服器發送。</p>';
        }
        if (!$consent) {
            echo '<p>此訂單未具備有效的行銷追蹤同意，無法發送 Meta 購買事件。</p>';
            return;
        }
        if (!WUTM_ATM_Purchase::valid_total($order)) return;
        $testing = WUTM_ATM_Events::settings()['test_code'] !== '';
        if (!$testing && !WUTM_ATM_Purchase::has_legacy_browser($tests)) {
            if (!$paid && !$row && WUTM_ATM_Purchase::manual_available($order)) {
                if ($order->has_status(['pending','on-hold'])) echo '<p>訂單目前待付款。可先循 WooCommerce 收款流程處理，或依本站人工收款規則記錄確認。</p>';
                $label = WUTM_ATM_Events::server_active() ? '確認收款並送出購買事件' : '確認收款';
                self::button($order, 'confirm', $label, '請確認此筆訂單已實際收到款項。此操作將記錄人工收款確認，並依目前追蹤設定建立 Meta 購買事件。');
            } elseif (WUTM_ATM_Purchase::fresh($paid) && WUTM_ATM_Events::server_active()) {
                if (!$row || $row->status === 'browser_prepared') self::button($order, 'send', '送出購買事件（Purchase）');
                elseif (WUTM_ATM_Purchase::retryable($row, $order)) self::button($order, 'retry', '重試原有事件');
            }
        }
        if ($row && in_array($row->status, ['pending','sending','retry','failed'], true)) {
            echo '<p><a href="' . esc_url($order->get_edit_order_url() . '#wutm-atm-purchase') . '">檢查發送狀態</a></p>';
        }
        if (current_user_can('manage_woocommerce') && ($test = WUTM_ATM_Purchase::test_config())) {
            echo '<hr><h4>模擬測試</h4><p>此為模擬 Purchase 測試，只驗證事件參數及追蹤發送，不代表實際金流付款成功。</p>';
            if ($test['dataset'] === WUTM_ATM_Events::dataset()) {
                echo '<p>目前使用正式資料集搭配測試代碼。Meta 仍可能接收或使用此事件，可能影響報表。</p>';
            }
            self::button($order, 'test', '模擬 Purchase 測試', '我已指定本訂單供測試使用。');
        }
    }

    private static function button(WC_Order $order, string $operation, string $label, string $confirm = ''): void {
        // The order editor already has a form. Link inputs to a separate footer form.
        $form = 'wutm-atm-purchase-' . $operation;
        if ($confirm !== '') {
            echo '<p><label><input type="checkbox" name="verified" value="yes" required form="' .
                esc_attr($form) . '"> ' . esc_html($confirm) . '</label></p>';
        }
        echo '<p><button class="button" type="submit" form="' . esc_attr($form) . '">' . esc_html($label) . '</button></p>';
        add_action('admin_footer', static function () use ($order, $operation, $form) {
            echo '<form id="' . esc_attr($form) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field(self::ACTION . '_' . $order->get_id(), '_wpnonce', false);
            echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
            echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $order->get_id()) . '">';
            echo '<input type="hidden" name="operation" value="' . esc_attr($operation) . '"></form>';
        });
    }

    public static function column(array $columns): array {
        $columns[self::COLUMN] = 'Meta 購買事件';
        return $columns;
    }

    public static function capture_list_query(array $args): array {
        add_filter('woocommerce_order_query', [__CLASS__, 'capture_list_results']);
        return $args;
    }

    public static function capture_list_results($results) {
        remove_filter('woocommerce_order_query', [__CLASS__, 'capture_list_results']);
        $orders = is_object($results) ? $results->orders : $results;
        $ids = array_map(static function ($order) { return $order instanceof WC_Order ? $order->get_id() : absint($order); }, (array) $orders);
        self::$list_rows = WUTM_ATM_Purchase::rows($ids, false);
        return $results;
    }

    public static function legacy_column(string $column, int $id): void {
        if ($column !== self::COLUMN) return;
        global $wp_query;
        if (self::$list_rows === null) self::$list_rows = WUTM_ATM_Purchase::rows(wp_list_pluck($wp_query->posts, 'ID'), false);
        $status = substr((string) get_post_status($id), 3);
        self::label($id, $status, get_edit_post_link($id, 'raw'));
    }

    public static function render_column(string $column, WC_Order $order): void {
        if ($column !== self::COLUMN) return;
        self::label($order->get_id(), $order->get_status(), $order->get_edit_order_url());
    }

    private static function label(int $id, string $status, string $link): void {
        $label = in_array($status, wc_get_is_paid_statuses(), true) ? '未發送' : '未付款';
        foreach (self::$list_rows ?? [] as $row) {
            if ((int) $row->order_id !== $id || $row->test_mode == 1) continue;
            $labels = ['sent'=>'已接收','failed'=>'發送失敗','consent_blocked'=>'同意限制','browser_prepared'=>'瀏覽器待驗證'];
            $label = $labels[$row->status] ?? '等待發送';
            if ($row->status === 'sent' && !(int) $row->received) $label = '待確認接收';
            break;
        }
        echo '<a href="' . esc_url($link . '#wutm-atm-purchase') . '">' . esc_html($label) . '</a>';
    }
}
