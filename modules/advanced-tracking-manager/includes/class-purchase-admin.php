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
        add_meta_box('wutm-atm-purchase', 'Meta 購買事件', [__CLASS__, 'render'], $screen, 'side', 'default');
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
        if (!in_array($action, ['confirm','send'], true) ||
            ($action === 'confirm' && ($_POST['verified'] ?? '') !== 'yes')) {
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
        $status = self::order_status($order, $row, $tests, $paid, $consent);
        $messages = [
            'queued'=>'購買事件正在等待回傳。','confirmed'=>'已記錄收款確認。',
            'existing'=>'此訂單已有購買事件。','consent'=>'未取得追蹤同意。',
            'invalid_total'=>'請確認訂單金額及幣別。','unpaid'=>'訂單目前無法回傳。',
            'unavailable'=>'請重新檢查訂單狀態。','busy'=>'請重新整理後再試。',
        ];
        $result = is_string($_GET['wutm_purchase_result'] ?? null) ? sanitize_key($_GET['wutm_purchase_result']) : '';
        if (isset($messages[$result])) echo '<p role="status">' . esc_html($messages[$result]) . '</p>';
        echo '<p><strong>' . esc_html($status) . '</strong></p>';
        echo '<p>訂單金額 ' . wp_kses_post(wc_price($order->get_total(), ['currency'=>$order->get_currency()])) . '</p>';
        if ($status === '已回傳') {
            echo '<p>已回傳金額 ' . wp_kses_post(wc_price($row->amount, ['currency'=>$row->currency])) . '<br>回傳時間 ' .
                esc_html(wp_date('Y-m-d H:i:s', (int) $row->updated)) . '</p>';
            return;
        }
        if ($status === '待技術確認') {
            echo '<p>此狀態僅涉及 Meta 廣告追蹤，不影響訂單付款及出貨流程。</p>';
            return;
        }
        if (!$consent || !WUTM_ATM_Purchase::valid_total($order) || WUTM_ATM_Purchase::has_legacy_browser($tests)) return;
        if (!$paid && !$row && WUTM_ATM_Purchase::manual_available($order)) {
            $label = WUTM_ATM_Events::server_active() ? '確認收款並回傳購買事件' : '確認收款';
            self::button($order, 'confirm', $label, '我確認此筆訂單已實際收到款項。');
        } elseif (WUTM_ATM_Purchase::fresh($paid) && (!$row || $row->status === 'browser_prepared') && WUTM_ATM_Events::server_active()) {
            self::button($order, 'send', '回傳購買事件');
        }
    }

    private static function order_status(WC_Order $order, $row, array $tests, array $paid, bool $consent): string {
        if ($row && $row->status === 'sent' && (int) $row->received > 0) return '已回傳';
        if (($row && in_array($row->status, ['failed','sent'], true)) || WUTM_ATM_Purchase::has_legacy_browser($tests)) return '待技術確認';
        if ($order->has_status(['cancelled','failed','refunded','trash','checkout-draft']) || $order->get_total_refunded() > 0) return '不需回傳';
        if ($paid && !$consent) return '未取得追蹤同意';
        if (!$paid) return WUTM_ATM_Purchase::manual_available($order) ? '等待確認收款' : '尚未付款';
        if (!WUTM_ATM_Purchase::fresh($paid) && !$row) return '不需回傳';
        if ($row && $row->status === 'consent_blocked') return '未取得追蹤同意';
        if ($row && $row->status === 'browser_prepared' && ((int) $row->browser_result & WUTM_ATM_Browser_Evidence::HANDOFF)) {
            return '已交由瀏覽器處理，接收待確認';
        }
        if ($row && in_array($row->status, ['retry','sending'], true)) return '系統處理中';
        return '等待回傳';
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
        $label = in_array($status, wc_get_is_paid_statuses(), true) ? '待確認' : '尚未付款';
        foreach (self::$list_rows ?? [] as $row) {
            if ((int) $row->order_id !== $id || $row->test_mode == 1) continue;
            $labels = ['sent'=>'已回傳','failed'=>'待技術確認','consent_blocked'=>'未取得追蹤同意','browser_prepared'=>'接收待確認'];
            $label = $labels[$row->status] ?? '等待回傳';
            if ($row->status === 'sent' && !(int) $row->received) $label = '待確認接收';
            break;
        }
        echo '<a href="' . esc_url($link . '#wutm-atm-purchase') . '">' . esc_html($label) . '</a>';
    }
}
