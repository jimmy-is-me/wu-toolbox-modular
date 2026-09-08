<?php
/** WooCommerce ATM／銀行轉帳優化。 */
defined('ABSPATH') || exit;

final class WUTM_ATM_Transfer_Optimizer {
    public function __construct() {
        if (!class_exists('WooCommerce')) return;
        add_filter('gettext', [$this, 'translate_bacs_fields'], 20, 3);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'order_actions'], 10, 2);
        add_filter('woocommerce_my_account_my_orders_action_classes', [$this, 'order_action_classes'], 10, 2);
        add_action('woocommerce_order_details_before_order_table', [$this, 'render_order_section'], 10, 1);
        add_action('woocommerce_thankyou_bacs', [$this, 'render_thankyou_section'], 8, 1);
        add_action('template_redirect', [$this, 'handle_report']);
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'add_column']);
        add_filter('manage_edit-shop_order_columns', [$this, 'add_column']);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render_hpos_column'], 10, 2);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_legacy_column'], 10, 2);
        add_action('add_meta_boxes', [$this, 'add_metabox']);
        add_filter('woocommerce_order_actions', [$this, 'add_reminder_action']);
        add_action('woocommerce_order_action_wutm_send_bacs_reminder', [$this, 'send_reminder']);
        add_action('admin_menu', [$this, 'register_dashboard'], 60);
        add_action('admin_init', [$this, 'handle_dashboard_actions']);
    }

    public function translate_bacs_fields($translated, $text, $domain) {
        if (!is_admin() || $domain !== 'woocommerce') return $translated;
        $map = ['Account name' => '帳戶名稱（戶名）', 'Account Name' => '帳戶名稱（戶名）', 'Account details' => '帳戶詳細資訊', 'Account Details' => '帳戶詳細資訊', 'Bank name' => '銀行名稱（含銀行代碼）', 'Bank Name' => '銀行名稱（含銀行代碼）', 'Sort code' => '分行代碼', 'Sort Code' => '分行代碼', 'Account number' => '銀行帳號', 'Account Number' => '銀行帳號', 'IBAN' => '備註說明', 'BIC / Swift' => 'SWIFT 代碼'];
        return $map[$text] ?? $translated;
    }

    public function enqueue_styles(): void {
        if (!(is_account_page() || is_wc_endpoint_url('order-received') || is_wc_endpoint_url('view-order'))) return;
        wp_register_style('wutm-atm-transfer', false, [], WUTM_VERSION);
        wp_enqueue_style('wutm-atm-transfer');
        wp_add_inline_style('wutm-atm-transfer', '.woocommerce-order>.woocommerce-bacs-bank-details{display:none!important}.wutm-bacs-card{max-width:650px;margin:15px 0 12px;padding:14px 18px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc}.wutm-bacs-card h4,.wutm-transfer-report h4{margin:0 0 8px;font-size:.95rem;font-weight:700}.wutm-bacs-card h4{color:#334155}.wutm-bacs-account{display:block;margin-top:6px;padding:10px 14px;border:1px solid #e2e8f0;border-radius:6px;background:#fff}.wutm-bacs-account.is-selected{border-color:#2563eb;box-shadow:0 0 0 1px #2563eb}.wutm-bacs-account input{width:auto;margin-right:7px}.wutm-bacs-account-name{margin-bottom:4px;color:#0369a1;font-size:.9rem;font-weight:600}.wutm-bacs-row{color:#475569;font-size:.88rem;line-height:1.6}.wutm-bacs-number{color:#b91c1c;font-size:1rem;font-weight:700;letter-spacing:.5px}.wutm-transfer-report{max-width:650px;margin:0 0 20px;padding:14px 18px;border:1px solid #cbd5e1;border-radius:8px;background:#fff}.wutm-transfer-report p{margin:0 0 10px;color:#64748b;font-size:.85rem}.wutm-transfer-report.is-done{background:#f0fdf4;border-color:#bbf7d0}.wutm-transfer-form{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:0}.wutm-transfer-form input{width:170px;padding:6px 12px;border:1px solid #cbd5e1;border-radius:6px}.wutm-transfer-button{padding:7px 16px!important;border:0!important;border-radius:6px!important;background:#2563eb!important;color:#fff!important;font-size:13.5px!important;font-weight:600!important}.woocommerce-orders-table .button.wutm-bacs-info{margin-left:5px!important;border:1px solid #cbd5e1!important;background:#f1f5f9!important;color:#334155!important}');
    }

    public function order_actions(array $actions, $order): array {
        if ($order instanceof WC_Order && $order->get_payment_method() === 'bacs') {
            $actions['wutm_bacs_info'] = ['url' => $order->get_view_order_url() . '#wutm-bacs-section', 'name' => $this->reported($order) ? '轉帳資訊（已回報）' : '轉帳資訊'];
        }
        return $actions;
    }

    public function order_action_classes($classes, string $action) {
        if ($action === 'wutm_bacs_info') {
            if (is_array($classes)) $classes[] = 'wutm-bacs-info';
            elseif (is_string($classes)) $classes .= ' wutm-bacs-info';
        }
        return $classes;
    }

    public function render_thankyou_section($order_id): void {
        $order = wc_get_order($order_id);
        if ($order) $this->render_order_section($order);
    }

    public function render_order_section($order): void {
        static $rendered = [];
        if (!($order instanceof WC_Order) || $order->get_payment_method() !== 'bacs' || isset($rendered[$order->get_id()])) return;
        $rendered[$order->get_id()] = true;
        $accounts = $this->accounts();
        $reported = $this->reported($order);
        $selected_account = $this->order_account_id($order);
        $form_id = 'wutm-transfer-form-' . $order->get_id();
        echo '<div id="wutm-bacs-section">';
        if ($accounts) {
            echo '<div class="wutm-bacs-card"><h4>🏦 匯款／轉帳帳號資訊</h4>';
            if (!$reported && count($accounts) > 1) echo '<p>請選擇實際匯入的收款帳戶，再提交轉帳回報。</p>';
            foreach ($accounts as $account) {
                $selected = $selected_account !== '' && hash_equals($selected_account, $account['_wutm_id']);
                echo '<label class="wutm-bacs-account' . ($selected ? ' is-selected' : '') . '">';
                if (!$reported && count($accounts) > 1) echo '<input type="radio" form="' . esc_attr($form_id) . '" name="wutm_transfer_account" value="' . esc_attr($account['_wutm_id']) . '" required ' . checked($selected, true, false) . '>';
                if (!empty($account['account_name'])) echo '<div class="wutm-bacs-account-name">' . esc_html($account['account_name']) . '</div>';
                echo '<div class="wutm-bacs-row">';
                if (!empty($account['bank_name'])) echo '銀行：' . esc_html($account['bank_name']) . (!empty($account['sort_code']) ? '（' . esc_html($account['sort_code']) . '）' : '') . '&nbsp;&nbsp;|&nbsp;&nbsp;';
                if (!empty($account['account_number'])) echo '帳號：<span class="wutm-bacs-number">' . esc_html($account['account_number']) . '</span>';
                echo '</div></label>';
            }
            echo '</div>';
        }
        if ($reported) {
            echo '<div class="wutm-transfer-report is-done"><h4>✅ 已完成轉帳回報</h4><p>回報時間：' . esc_html($this->transfer_meta($order, 'time'));
            $digits = $this->transfer_meta($order, 'digits');
            if ($digits !== '') echo '　帳號末碼：<strong>' . esc_html($digits) . '</strong>';
            echo '<br>收款帳戶：<strong>' . esc_html($this->order_account_label($order)) . '</strong>';
            echo '<br>款項核對中，確認入帳後將為您安排後續處理。</p></div>';
        } else {
            echo '<div class="wutm-transfer-report"><h4>📢 轉帳完成回報</h4><p>完成轉帳後，選擇收款帳戶並填寫帳號末五碼協助商家對帳。</p><form id="' . esc_attr($form_id) . '" method="post" class="wutm-transfer-form">';
            wp_nonce_field('wutm_report_transfer_' . $order->get_id(), 'wutm_report_transfer_nonce');
            if (count($accounts) === 1) echo '<input type="hidden" name="wutm_transfer_account" value="' . esc_attr($accounts[0]['_wutm_id']) . '">';
            echo '<input type="hidden" name="wutm_transfer_order_id" value="' . esc_attr((string) $order->get_id()) . '"><input type="hidden" name="wutm_transfer_order_key" value="' . esc_attr($order->get_order_key()) . '"><input type="text" name="wutm_transfer_digits" inputmode="numeric" pattern="[0-9]{0,8}" maxlength="8" placeholder="帳號末五碼（選填）"><button type="submit" name="wutm_submit_transfer" value="1" class="button wutm-transfer-button">回報已轉帳</button></form></div>';
        }
        echo '</div>';
    }

    public function handle_report(): void {
        if (empty($_POST['wutm_submit_transfer'])) return;
        $order_id = absint($_POST['wutm_transfer_order_id'] ?? 0);
        $nonce = sanitize_text_field(wp_unslash($_POST['wutm_report_transfer_nonce'] ?? ''));
        if (!$order_id || !wp_verify_nonce($nonce, 'wutm_report_transfer_' . $order_id)) return;
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'bacs') return;
        $allowed = $order->get_user_id() > 0
            ? get_current_user_id() === $order->get_user_id()
            : hash_equals($order->get_order_key(), sanitize_text_field(wp_unslash($_POST['wutm_transfer_order_key'] ?? '')));
        if (!$allowed && !current_user_can('manage_woocommerce')) return;
        $accounts = $this->accounts();
        $account = $this->find_account(sanitize_key(wp_unslash($_POST['wutm_transfer_account'] ?? '')));
        $redirect = wp_get_referer() ?: $order->get_view_order_url();
        if (count($accounts) > 1 && !$account) {
            if (function_exists('wc_add_notice')) wc_add_notice('請選擇實際匯入的收款帳戶。', 'error');
            wp_safe_redirect($redirect);
            exit;
        }
        if (!$account && count($accounts) === 1) $account = $accounts[0];
        $digits = preg_replace('/\D+/', '', (string) wp_unslash($_POST['wutm_transfer_digits'] ?? ''));
        $digits = substr($digits, 0, 8);
        $time = current_time('Y-m-d H:i:s');
        $order->update_meta_data('_wutm_customer_reported_transfer', 'yes');
        $order->update_meta_data('_wutm_customer_transfer_time', $time);
        $order->update_meta_data('_wutm_customer_transfer_digits', $digits);
        // Keep the reference implementation's keys in sync so existing sites
        // and reports can use either naming convention.
        $order->update_meta_data('_customer_reported_transferred', 'yes');
        $order->update_meta_data('_customer_transferred_time', $time);
        $order->update_meta_data('_customer_transfer_account_digits', $digits);
        if ($account) $this->save_order_account($order, $account);
        $order->add_order_note('【顧客回報已轉帳】時間：' . $time . ($digits ? '，帳號末碼：' . $digits : '') . ($account ? '，收款帳戶：' . $this->account_label($account) : ''));
        $order->save();
        wp_safe_redirect(add_query_arg('transfer-reported', '1', $redirect));
        exit;
    }

    public function add_column(array $columns): array {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'order_status') $new['wutm_bacs_status'] = '匯款回報';
        }
        return $new;
    }

    public function render_hpos_column(string $column, $order): void {
        if ($column === 'wutm_bacs_status' && $order instanceof WC_Order) echo wp_kses_post($this->status_badge($order));
    }

    public function render_legacy_column(string $column, $post_id): void {
        if ($column !== 'wutm_bacs_status') return;
        $order = wc_get_order($post_id);
        if ($order) echo wp_kses_post($this->status_badge($order));
    }

    private function status_badge(WC_Order $order): string {
        if ($order->get_payment_method() !== 'bacs') return '<span style="color:#94a3b8">—</span>';
        if ($order->is_paid()) return '<span style="padding:3px 8px;border-radius:4px;background:#f1f5f9;color:#475569">已完成</span>';
        if ($this->reported($order)) {
            $digits = $this->transfer_meta($order, 'digits');
            return '<span style="padding:3px 8px;border-radius:4px;background:#dcfce7;color:#15803d">已回報' . ($digits ? '（末碼 ' . esc_html($digits) . '）' : '') . '</span>';
        }
        return '<span style="padding:3px 8px;border-radius:4px;background:#fef3c7;color:#92400e">未回報</span>';
    }

    public function add_metabox(): void {
        $screen = 'shop_order';
        if (function_exists('wc_get_page_screen_id')) $screen = wc_get_page_screen_id('shop-order');
        add_meta_box('wutm_bacs_report', '匯款對帳狀態', [$this, 'render_metabox'], $screen, 'side', 'high');
        if ($screen !== 'shop_order') add_meta_box('wutm_bacs_report', '匯款對帳狀態', [$this, 'render_metabox'], 'shop_order', 'side', 'high');
    }

    public function render_metabox($object): void {
        $order = $object instanceof WP_Post ? wc_get_order($object->ID) : $object;
        if (!($order instanceof WC_Order) || $order->get_payment_method() !== 'bacs') { echo '<p>此訂單不是銀行轉帳付款。</p>'; return; }
        echo wp_kses_post($this->status_badge($order));
        if ($this->transfer_meta($order, 'time')) echo '<p>回報時間：' . esc_html($this->transfer_meta($order, 'time')) . '</p>';
        echo '<p>收款帳戶：<strong>' . esc_html($this->order_account_label($order)) . '</strong></p>';
    }

    public function add_reminder_action(array $actions): array {
        global $theorder;
        if ($theorder instanceof WC_Order && $theorder->get_payment_method() === 'bacs' && !$theorder->is_paid()) $actions['wutm_send_bacs_reminder'] = '發送 ATM 轉帳付款提醒';
        return $actions;
    }

    public function send_reminder(WC_Order $order): void {
        $to = $order->get_billing_email();
        if (!$to) { $order->add_order_note('ATM 付款提醒寄送失敗：沒有顧客 Email。'); return; }
        $content = '<p>' . esc_html($order->get_formatted_billing_full_name()) . ' 您好：</p><p>訂單 <strong>#' . esc_html($order->get_order_number()) . '</strong> 尚待 ATM／銀行轉帳付款，訂單金額為 ' . wp_kses_post($order->get_formatted_order_total()) . '。</p>' . $this->accounts_email_html() . '<p><a href="' . esc_url($order->get_view_order_url()) . '">查看訂單並回報轉帳</a></p>';
        $mailer = WC()->mailer();
        $mailer->send($to, '【付款提醒】訂單 #' . $order->get_order_number() . ' 尚待轉帳', $mailer->wrap_message('ATM 轉帳付款提醒', $content), ['Content-Type: text/html; charset=UTF-8']);
        $order->add_order_note('已寄送 ATM 轉帳付款提醒至 ' . sanitize_email($to));
    }

    public function register_dashboard(): void {
        add_submenu_page('woocommerce', '銀行轉帳對帳中心', '銀行轉帳對帳中心', 'manage_woocommerce', 'wc-bacs-dashboard', [$this, 'render_dashboard']);
    }

    public function handle_dashboard_actions(): void {
        if (($_GET['page'] ?? '') !== 'wc-bacs-dashboard' || !current_user_can('manage_woocommerce')) return;
        $base_url = admin_url('admin.php?page=wc-bacs-dashboard');
        if (!empty($_POST['wutm_bacs_save_account'])) {
            $order_id = absint($_POST['order_id'] ?? 0);
            check_admin_referer('wutm_bacs_account_' . $order_id);
            $order = wc_get_order($order_id);
            $account = $this->find_account(sanitize_key(wp_unslash($_POST['account_id'] ?? '')));
            if ($order instanceof WC_Order && $order->get_payment_method() === 'bacs' && $account) {
                $this->save_order_account($order, $account);
                $order->add_order_note('【銀行轉帳對帳中心】指定收款帳戶：' . $this->account_label($account));
                $order->save();
                wp_safe_redirect(add_query_arg('account_saved', '1', $base_url));
                exit;
            }
            wp_safe_redirect(add_query_arg('account_error', '1', $base_url));
            exit;
        }
        $action = sanitize_key(wp_unslash($_GET['action'] ?? ''));
        $order_id = absint($_GET['order_id'] ?? 0);
        if (!$action || !$order_id) return;
        $order = wc_get_order($order_id);
        if (!($order instanceof WC_Order) || $order->get_payment_method() !== 'bacs') return;
        if ($action === 'confirm_payment') {
            check_admin_referer('wutm_bacs_confirm_' . $order_id);
            $order->payment_complete();
            $order->add_order_note('【銀行轉帳對帳中心】管理員確認款項已入帳。');
            wp_safe_redirect(add_query_arg('payment_confirmed', '1', $base_url));
            exit;
        }
        if ($action === 'send_reminder') {
            check_admin_referer('wutm_bacs_reminder_' . $order_id);
            $this->send_reminder($order);
            wp_safe_redirect(add_query_arg('reminder_sent', '1', $base_url));
            exit;
        }
    }

    public function render_dashboard(): void {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('您沒有管理對帳中心的權限。', 'wu-toolbox-modular'));
        $orders = wc_get_orders(['payment_method' => 'bacs', 'limit' => 150, 'orderby' => 'date', 'order' => 'DESC']);
        $reported_count = 0;
        $unreported_count = 0;
        $paid_count = 0;
        $pending_amount = 0.0;
        foreach ($orders as $order) {
            if ($order->is_paid()) $paid_count++;
            elseif ($this->reported($order)) { $reported_count++; $pending_amount += (float) $order->get_total(); }
            else $unreported_count++;
        }
        $accounts = $this->accounts();
        ?>
        <div class="wrap wutm-bacs-dashboard">
            <style>
                .wutm-bacs-dashboard{padding:20px 20px 40px 0}.wutm-bacs-dashboard *{box-sizing:border-box}.wutm-bacs-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:15px;margin:20px 0}.wutm-bacs-stat,.wutm-bacs-panel{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px;box-shadow:0 1px 3px rgba(0,0,0,.03)}.wutm-bacs-stat.reported{border-left:4px solid #10b981}.wutm-bacs-stat.waiting{border-left:4px solid #f59e0b}.wutm-bacs-stat .label{font-size:13px;color:#64748b;font-weight:500}.wutm-bacs-stat .num{font-size:26px;font-weight:700;margin-top:6px;color:#1e293b}.wutm-bacs-panel{padding:20px;margin-bottom:25px;overflow:auto}.wutm-bacs-panel h3{margin:0 0 14px;display:flex;align-items:center;justify-content:space-between;gap:12px}.wutm-bacs-table{width:100%;border-collapse:collapse;text-align:left;font-size:13.5px;min-width:850px}.wutm-bacs-table th{background:#f8fafc;padding:12px;border-bottom:1px solid #e2e8f0;color:#475569}.wutm-bacs-table td{padding:12px;border-bottom:1px solid #f1f5f9;color:#334155;vertical-align:middle}.wutm-bacs-table tr:hover td{background:#f8fafc}.wutm-soft-badge{display:inline-block;padding:3px 8px;border-radius:4px;font-size:12px;font-weight:600}.wutm-badge-green{background:#dcfce7;color:#15803d;border:1px solid #bbf7d0}.wutm-badge-amber{background:#fef3c7;color:#92400e;border:1px solid #fde68a}.wutm-badge-gray{background:#f1f5f9;color:#64748b}.wutm-bacs-action{display:inline-block;padding:4px 10px;border-radius:4px;text-decoration:none;font-size:12px;font-weight:600;margin:2px}.wutm-bacs-confirm{background:#22c55e;color:#fff!important}.wutm-bacs-remind{background:#f8fafc;border:1px solid #cbd5e1;color:#475569!important}
            </style>
            <h1>🏦 銀行轉帳對帳中心</h1>
            <?php if (isset($_GET['payment_confirmed'])) : ?><div class="notice notice-success is-dismissible"><p>已確認款項入帳並更新訂單狀態。</p></div><?php endif; ?>
            <?php if (isset($_GET['reminder_sent'])) : ?><div class="notice notice-success is-dismissible"><p>付款提醒信已寄送。</p></div><?php endif; ?>
            <?php if (isset($_GET['account_saved'])) : ?><div class="notice notice-success is-dismissible"><p>訂單收款帳戶已儲存。</p></div><?php endif; ?>
            <?php if (isset($_GET['account_error'])) : ?><div class="notice notice-error is-dismissible"><p>無法儲存收款帳戶，請重新選擇。</p></div><?php endif; ?>
            <div class="wutm-bacs-stats">
                <div class="wutm-bacs-stat reported"><div class="label">待核對入帳（顧客已回報）</div><div class="num" style="color:#059669"><?php echo esc_html((string) $reported_count); ?> <small>筆</small></div></div>
                <div class="wutm-bacs-stat waiting"><div class="label">等待顧客轉帳（未回報）</div><div class="num" style="color:#d97706"><?php echo esc_html((string) $unreported_count); ?> <small>筆</small></div></div>
                <div class="wutm-bacs-stat"><div class="label">已入帳／已處理</div><div class="num"><?php echo esc_html((string) $paid_count); ?> <small>筆</small></div></div>
                <div class="wutm-bacs-stat"><div class="label">待核對總金額</div><div class="num" style="color:#2563eb"><?php echo wp_kses_post(wc_price($pending_amount)); ?></div></div>
            </div>
            <div class="wutm-bacs-panel">
                <h3><span>📋 收款帳戶設定</span><a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=bacs')); ?>">修改銀行帳戶</a></h3>
                <?php if (!$accounts) : ?><p>尚未設定銀行轉帳帳戶。</p><?php else : ?>
                <table class="wutm-bacs-table"><thead><tr><th>帳戶名稱（戶名）</th><th>銀行名稱</th><th>分行代碼</th><th>銀行帳號</th></tr></thead><tbody>
                <?php foreach ($accounts as $account) : ?><tr><td><strong><?php echo esc_html($account['account_name'] ?? '—'); ?></strong></td><td><?php echo esc_html($account['bank_name'] ?? '—'); ?></td><td><?php echo esc_html($account['sort_code'] ?? '—'); ?></td><td><strong style="font-family:monospace;color:#b91c1c"><?php echo esc_html($account['account_number'] ?? '—'); ?></strong></td></tr><?php endforeach; ?>
                </tbody></table><?php endif; ?>
            </div>
            <div class="wutm-bacs-panel"><h3>📑 轉帳訂單對帳明細</h3>
                <table class="wutm-bacs-table"><thead><tr><th>訂單</th><th>下單日期</th><th>訂購人／Email</th><th>總計</th><th>收款帳戶</th><th>回報狀態</th><th>回報時間</th><th>訂單狀態</th><th>快捷操作</th></tr></thead><tbody>
                <?php if (!$orders) : ?><tr><td colspan="9" style="text-align:center">尚無銀行轉帳訂單</td></tr><?php endif; ?>
                <?php foreach ($orders as $order) :
                    $is_paid = $order->is_paid();
                    $is_reported = $this->reported($order);
                    $digits = $this->transfer_meta($order, 'digits');
                    $date = $order->get_date_created();
                    $confirm_url = wp_nonce_url(admin_url('admin.php?page=wc-bacs-dashboard&action=confirm_payment&order_id=' . $order->get_id()), 'wutm_bacs_confirm_' . $order->get_id());
                    $remind_url = wp_nonce_url(admin_url('admin.php?page=wc-bacs-dashboard&action=send_reminder&order_id=' . $order->get_id()), 'wutm_bacs_reminder_' . $order->get_id());
                ?><tr>
                    <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>"><strong>#<?php echo esc_html($order->get_order_number()); ?></strong></a></td>
                    <td><?php echo esc_html($date ? $date->date_i18n('Y-m-d H:i') : '—'); ?></td>
                    <td><?php echo esc_html($order->get_formatted_billing_full_name()); ?><br><small><?php echo esc_html($order->get_billing_email()); ?></small></td>
                    <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                    <td><form method="post" style="display:flex;gap:5px;align-items:center;min-width:210px"><?php wp_nonce_field('wutm_bacs_account_' . $order->get_id()); ?><input type="hidden" name="order_id" value="<?php echo esc_attr((string) $order->get_id()); ?>"><select name="account_id" required><option value="">未指定</option><?php foreach ($accounts as $account) : ?><option value="<?php echo esc_attr($account['_wutm_id']); ?>" <?php selected($this->order_account_id($order), $account['_wutm_id']); ?>><?php echo esc_html($this->account_label($account)); ?></option><?php endforeach; ?></select><button class="button button-small" name="wutm_bacs_save_account" value="1">儲存</button></form></td>
                    <td><?php if ($is_paid) : ?><span class="wutm-soft-badge wutm-badge-gray">款項已結清</span><?php elseif ($is_reported) : ?><span class="wutm-soft-badge wutm-badge-green">已回報<?php echo $digits ? '（末碼 ' . esc_html($digits) . '）' : ''; ?></span><?php else : ?><span class="wutm-soft-badge wutm-badge-amber">尚未回報</span><?php endif; ?></td>
                    <td><?php echo esc_html($this->transfer_meta($order, 'time') ?: '—'); ?></td>
                    <td><span class="wutm-soft-badge wutm-badge-gray"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></span></td>
                    <td><?php if (!$is_paid) : ?><a class="wutm-bacs-action wutm-bacs-confirm" href="<?php echo esc_url($confirm_url); ?>" onclick="return confirm('確定已收到此筆款項嗎？')">確認入帳</a><a class="wutm-bacs-action wutm-bacs-remind" href="<?php echo esc_url($remind_url); ?>" onclick="return confirm('確定寄送付款提醒嗎？')">發送提醒</a><?php else : ?><a class="button button-small" href="<?php echo esc_url($order->get_edit_order_url()); ?>">檢視</a><?php endif; ?></td>
                </tr><?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
        <?php
    }

    private function reported(WC_Order $order): bool {
        return $this->transfer_meta($order, 'reported') === 'yes';
    }

    private function transfer_meta(WC_Order $order, string $field): string {
        $keys = [
            'reported' => ['_wutm_customer_reported_transfer', '_customer_reported_transferred'],
            'time' => ['_wutm_customer_transfer_time', '_customer_transferred_time'],
            'digits' => ['_wutm_customer_transfer_digits', '_customer_transfer_account_digits'],
        ];
        foreach ($keys[$field] ?? [] as $key) {
            $value = (string) $order->get_meta($key);
            if ($value !== '') return $value;
        }
        return '';
    }

    private function accounts(): array {
        $accounts = array();
        foreach ((array) get_option('woocommerce_bacs_accounts', []) as $account) {
            if (!is_array($account)) continue;
            $identity = array_intersect_key($account, array_flip(array('account_name', 'bank_name', 'sort_code', 'account_number', 'iban', 'bic')));
            $account['_wutm_id'] = substr(hash('sha256', wp_json_encode($identity)), 0, 20);
            $accounts[] = $account;
        }
        return $accounts;
    }

    private function find_account(string $id): ?array {
        if ($id === '') return null;
        foreach ($this->accounts() as $account) {
            if (hash_equals($account['_wutm_id'], $id)) return $account;
        }
        return null;
    }

    private function account_label(array $account): string {
        $parts = array_filter(array((string) ($account['account_name'] ?? ''), (string) ($account['bank_name'] ?? ''), (string) ($account['account_number'] ?? '')));
        return $parts ? implode('／', $parts) : '未命名帳戶';
    }

    private function save_order_account(WC_Order $order, array $account): void {
        $order->update_meta_data('_wutm_bacs_account_id', (string) $account['_wutm_id']);
        $order->update_meta_data('_wutm_bacs_account_snapshot', array(
            'account_name' => sanitize_text_field((string) ($account['account_name'] ?? '')),
            'bank_name' => sanitize_text_field((string) ($account['bank_name'] ?? '')),
            'sort_code' => sanitize_text_field((string) ($account['sort_code'] ?? '')),
            'account_number' => sanitize_text_field((string) ($account['account_number'] ?? '')),
        ));
    }

    private function order_account_id(WC_Order $order): string {
        return sanitize_key((string) $order->get_meta('_wutm_bacs_account_id'));
    }

    private function order_account_label(WC_Order $order): string {
        $snapshot = $order->get_meta('_wutm_bacs_account_snapshot');
        if (is_array($snapshot) && array_filter($snapshot)) return $this->account_label($snapshot);
        $account = $this->find_account($this->order_account_id($order));
        if ($account) return $this->account_label($account);
        $accounts = $this->accounts();
        return count($accounts) === 1 ? $this->account_label($accounts[0]) : '未指定';
    }

    private function accounts_email_html(): string {
        $html = '<div><strong>匯款帳號：</strong><br>';
        foreach ((array) get_option('woocommerce_bacs_accounts', []) as $account) {
            $html .= esc_html((string) ($account['bank_name'] ?? '')) . '　' . esc_html((string) ($account['account_name'] ?? '')) . '　' . esc_html((string) ($account['account_number'] ?? '')) . '<br>';
        }
        return $html . '</div>';
    }
}

new WUTM_ATM_Transfer_Optimizer();
